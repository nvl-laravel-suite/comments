<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Nvl\Comments\Exceptions\CommentMutationLockConfigurationException;
use Throwable;

/**
 * Resolves one canonical production-capable cache lock domain for comment mutations.
 */
final class CommentMutationLockStore
{
    /**
     * Resolve and validate the configured atomic-lock provider.
     *
     * @throws CommentMutationLockConfigurationException
     */
    public function provider(
        ?string $configuredStore,
        bool $allowLocalStore,
    ): LockProvider {
        $storeName = $configuredStore ?? $this->defaultStoreName();
        $stores = config('cache.stores');

        if (! is_array($stores) || ! is_array($stores[$storeName] ?? null)) {
            throw new CommentMutationLockConfigurationException(
                "Cache store [{$storeName}] configured for comment mutation locks is not defined.",
            );
        }

        $driver = $stores[$storeName]['driver'] ?? null;

        if (! is_string($driver) || trim($driver) === '') {
            throw new CommentMutationLockConfigurationException(
                "Cache store [{$storeName}] must declare a non-blank driver for comment mutation locks.",
            );
        }

        $driver = strtolower(trim($driver));

        if (in_array($driver, ['array', 'failover', 'null'], true)) {
            throw new CommentMutationLockConfigurationException(
                "Cache store [{$storeName}] uses the [{$driver}] driver, which is unsafe for comment mutation locks. Configure one canonical shared lock store.",
            );
        }

        if ($driver === 'file' && ! $allowLocalStore) {
            throw new CommentMutationLockConfigurationException(
                "Cache store [{$storeName}] uses the file driver, which is single-host only. Set comments.mutation_lock.allow_local_store to true only for an intentional single-host deployment.",
            );
        }

        try {
            $store = Cache::store($storeName)->getStore();
        } catch (Throwable $exception) {
            throw new CommentMutationLockConfigurationException(
                "Cache store [{$storeName}] could not be resolved for comment mutation locks.",
                previous: $exception,
            );
        }

        if (! $store instanceof LockProvider) {
            throw new CommentMutationLockConfigurationException(
                "Cache store [{$storeName}] uses the [{$driver}] driver but does not implement Laravel's LockProvider contract.",
            );
        }

        return $store;
    }

    /**
     * Resolve the application default used when no dedicated store is configured.
     *
     * @return non-empty-string
     *
     * @throws CommentMutationLockConfigurationException
     */
    private function defaultStoreName(): string
    {
        $storeName = config('cache.default');

        if (! is_string($storeName)) {
            throw new CommentMutationLockConfigurationException(
                'cache.default must be a non-blank cache store name when comments.mutation_lock.store is null.',
            );
        }

        $storeName = trim($storeName);

        if ($storeName === '') {
            throw new CommentMutationLockConfigurationException(
                'cache.default must be a non-blank cache store name when comments.mutation_lock.store is null.',
            );
        }

        return $storeName;
    }
}
