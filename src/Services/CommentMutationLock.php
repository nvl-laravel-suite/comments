<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Nvl\Comments\Exceptions\CommentMutationBusyException;
use Nvl\Comments\Exceptions\CommentMutationLockConfigurationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Support\CommentMutationLockConfiguration;
use Throwable;

/**
 * Serializes comment lifecycle mutations through sorted, reentrant atomic identities.
 */
final class CommentMutationLock
{
    /** @var array<string, true> */
    private array $heldKeys = [];

    /**
     * Locks retained until their root transaction finishes.
     *
     * @var array<string, array{connection: string, level: int, locks: list<Lock>, keys: list<string>}>
     */
    private array $transactionLocks = [];

    private int $transactionLockSequence = 0;

    /**
     * Create the request-scoped comment mutation lock coordinator.
     */
    public function __construct(
        private readonly DatabaseTransactionsManager $transactions,
        private readonly CommentMutationLockStore $store,
    ) {}

    /**
     * Execute a comment mutation while holding one atomic identity lock.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws CommentMutationBusyException
     * @throws CommentMutationLockConfigurationException
     */
    public function execute(string $commentId, Closure $callback): mixed
    {
        return $this->executeMany([$commentId], $callback);
    }

    /**
     * Execute a mutation while holding every requested identity in sorted order.
     *
     * @template TResult
     *
     * @param  list<string>  $commentIds
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws CommentMutationBusyException
     * @throws CommentMutationLockConfigurationException
     */
    public function executeMany(array $commentIds, Closure $callback): mixed
    {
        $settings = CommentMutationLockConfiguration::settings();

        if (! $settings['enabled']) {
            return $callback();
        }

        $commentIds = array_values(array_unique(array_filter(
            $commentIds,
            static fn (string $commentId): bool => $commentId !== '',
        )));
        sort($commentIds, SORT_STRING);
        $commentIds = array_values(array_filter(
            $commentIds,
            fn (string $commentId): bool => ! isset($this->heldKeys[$this->lockKey($commentId)]),
        ));

        if ($commentIds === []) {
            return $callback();
        }

        $provider = $this->store->provider(
            $settings['store'],
            $settings['allow_local_store'],
        );
        $locks = [];
        $keys = [];

        try {
            foreach ($commentIds as $commentId) {
                $key = $this->lockKey($commentId);
                $lock = $provider->lock($key, $settings['seconds']);
                $lock->block($settings['wait_seconds']);
                $locks[] = $lock;
                $keys[] = $key;
                $this->heldKeys[$key] = true;
            }
        } catch (LockTimeoutException $exception) {
            $this->releaseLocks($locks, $keys);

            throw new CommentMutationBusyException(
                'Timed out while waiting for a concurrent comment mutation.',
                previous: $exception,
            );
        } catch (Throwable $exception) {
            $this->releaseLocks($locks, $keys);

            throw $exception;
        }

        $rootTransaction = $this->rootTransaction();

        if ($rootTransaction instanceof DatabaseTransactionRecord) {
            $transactionLock = $this->retainTransactionLocks(
                $rootTransaction,
                $locks,
                $keys,
            );
            $rootTransaction->addCallback(
                fn (): bool => $this->releaseTransactionLocks($transactionLock),
            );
        }

        try {
            $result = $callback();
        } catch (Throwable $exception) {
            if (! $rootTransaction instanceof DatabaseTransactionRecord) {
                $this->releaseLocks($locks, $keys);
            }

            throw $exception;
        }

        if (! $rootTransaction instanceof DatabaseTransactionRecord) {
            $this->releaseLocks($locks, $keys);
        }

        return $result;
    }

    /**
     * Release locks retained by a root transaction after it rolls back.
     */
    public function releaseAfterRollback(TransactionRolledBack $event): void
    {
        $connection = $event->connection;
        $connectionName = $connection->getName();
        $transactionLevel = $connection->transactionLevel();

        foreach ($this->transactionLocks as $token => $retained) {
            if ($retained['connection'] !== $connectionName
                || $transactionLevel >= $retained['level']) {
                continue;
            }

            $this->releaseTransactionLocks($token);
        }
    }

    /**
     * Build a stable cache key without exposing the comment identity.
     */
    private function lockKey(string $commentId): string
    {
        return 'comments:mutation:'.hash('sha256', $commentId);
    }

    /**
     * Find the root transaction that must retain acquired locks.
     */
    private function rootTransaction(): ?DatabaseTransactionRecord
    {
        $connection = DB::connection((new Comment)->getConnectionName());

        if ($connection->transactionLevel() === 0) {
            return null;
        }

        $root = $this->transactions
            ->callbackApplicableTransactions()
            ->filter(
                static fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $connection->getName(),
            )
            ->sortBy('level')
            ->first();

        return $root instanceof DatabaseTransactionRecord ? $root : null;
    }

    /**
     * Retain locks until the root transaction commits or rolls back.
     *
     * @param  list<Lock>  $locks
     * @param  list<string>  $keys
     */
    private function retainTransactionLocks(
        DatabaseTransactionRecord $transaction,
        array $locks,
        array $keys,
    ): string {
        $token = 'transaction-lock:'.++$this->transactionLockSequence;
        $this->transactionLocks[$token] = [
            'connection' => $transaction->connection,
            'level' => $transaction->level,
            'locks' => $locks,
            'keys' => $keys,
        ];

        return $token;
    }

    /**
     * Release one retained transaction lock group exactly once.
     */
    private function releaseTransactionLocks(string $token): bool
    {
        $retained = $this->transactionLocks[$token] ?? null;

        if ($retained === null) {
            return true;
        }

        unset($this->transactionLocks[$token]);

        return $this->releaseLocks($retained['locks'], $retained['keys']);
    }

    /**
     * Release acquired locks in reverse order and clear reentrant state.
     *
     * @param  list<Lock>  $locks
     * @param  list<string>  $keys
     */
    private function releaseLocks(array $locks, array $keys): bool
    {
        foreach (array_reverse($locks) as $lock) {
            $lock->release();
        }

        foreach ($keys as $key) {
            unset($this->heldKeys[$key]);
        }

        return true;
    }
}
