<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Exceptions\CommentTargetNotFoundException;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Ownership\CommentsOwnership;
use Nvl\Comments\Support\CommentBatchOwnerAdmission;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Comments\Support\CommentTargetIdentifier;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/**
 * Reassigns complete comment threads during an authorized owner merge.
 *
 * @api
 *
 * @phpstan-type Identity array{type:string,id:string,hash:string}
 */
final readonly class CommentHistoryMover
{
    /** Use canonical model declarations and the same partition boundary as normal writers. */
    public function __construct(private OwnerRegistry $owners, private OwnershipBoundary $boundary, private CommentAccessService $access) {}

    /**
     * Move each original source history once, including deleted rows, inside the writer transaction.
     * Both target types must be declared owners on the comments connection. The host must grant
     * MoveHistory for both directions. Each mapping preserves the thread's record identities.
     *
     * @param  array<array-key,mixed>  $targets  Destination keys, indexed by historical owner key
     * @return array<array-key,int> Number of moved comments per supplied source key
     */
    public function moveMany(Authenticatable|SystemActor $actor, string $sourceType, string $targetType, array $targets): array
    {
        if ($targets === []) {
            return [];
        }
        $actor = CommentActor::from($actor);
        $sourceModel = new ($this->owners->model($sourceType));
        $targetModel = new ($this->owners->model($targetType));
        $connection = CommentModelRegistry::newInstance()->getConnection();
        if ($sourceModel->getConnection() !== $connection || $targetModel->getConnection() !== $connection) {
            throw new InvalidCommentMutationException('Comment history merges require source, destination and comments on the same connection.');
        }

        $pairs = [];
        $requests = [];
        $sources = [];
        foreach ($targets as $source => $target) {
            $from = $this->identity($sourceModel, $source);
            $to = $this->identity($targetModel, $target);
            if (isset($sources[$from['hash']])) {
                throw new InvalidCommentMutationException('Comment history contains duplicate canonical source identities.');
            }
            $sources[$from['hash']] = true;
            $pairs[$source] = [$from, $to];
            $requests[$from['hash']] = [$sourceModel, $from];
            $requests[$to['hash']] = [$targetModel, $to];
        }
        ksort($requests, SORT_STRING);

        return $connection->transaction(function () use ($actor, $requests, $pairs): array {
            $locked = [];
            foreach ($requests as $hash => [$prototype, $identity]) {
                $locked[$hash] = $this->lockOwner($prototype, $identity);
            }
            $histories = [];
            foreach ($pairs as $source => [$from, $to]) {
                $this->access->authorize(CommentAbility::MoveHistory, $actor, target: $locked[$from['hash']], audience: CommentAudience::Management, context: ['direction' => 'source', 'counterpart' => $to]);
                $this->access->authorize(CommentAbility::MoveHistory, $actor, target: $locked[$to['hash']], audience: CommentAudience::Management, context: ['direction' => 'destination', 'counterpart' => $from]);
                $histories[$source] = $from === $to ? [] : $this->lockHistory($from);
            }

            $moved = [];
            foreach ($pairs as $source => [$from, $to]) {
                $moved[$source] = 0;
                foreach (array_chunk($histories[$source], 500) as $ids) {
                    $count = $this->comments($from)->whereKey($ids)->toBase()->update([
                        'commentable_type' => $to['type'],
                        'commentable_id' => $to['id'],
                        'commentable_identity_hash' => $to['hash'],
                    ]);
                    if ($count !== count($ids)) {
                        throw new InvalidCommentMutationException('A locked comment history changed during reassignment.');
                    }
                    $moved[$source] += $count;
                }
            }

            return $moved;
        }, attempts: CommentsConfiguration::positiveInteger('nvl-comments.transactions.attempts', 3));
    }

    /** @return Identity */
    private function identity(Model $prototype, mixed $key): array
    {
        $id = (string) CommentTargetIdentifier::lookupKey($prototype, $key);
        $type = $prototype->getMorphClass();

        return ['type' => $type, 'id' => $id, 'hash' => CommentIdentity::pair($type, $id)];
    }

    /** @param Identity $identity */
    private function lockOwner(Model $prototype, array $identity): Model
    {
        $query = $prototype->newQuery()->useWritePdo();
        if (CommentsOwnership::active()) {
            $query = $this->boundary->query($query, CommentsOwnership::resource($prototype));
        }
        $owner = $query->lockForUpdate()->find(CommentTargetIdentifier::storedKey($prototype, $identity['id']));
        if (! $owner instanceof Model || CommentTargetIdentifier::canonical($owner) !== ['type' => $identity['type'], 'id' => $identity['id']]) {
            throw CommentTargetNotFoundException::forIdentifier($identity['type'], $identity['id']);
        }

        return $owner;
    }

    /**
     * @param  Identity  $identity
     * @return list<string>
     */
    private function lockHistory(array $identity): array
    {
        $rows = $this->comments($identity)->orderBy('id')->lockForUpdate()->get(['id', 'root_id', 'parent_id'])->keyBy('id');
        foreach ($rows as $row) {
            if (($row->root_id !== null && ! $rows->has($row->root_id)) || ($row->parent_id !== null && ! $rows->has($row->parent_id))) {
                throw new InvalidCommentMutationException('A comment history must retain every original parent and root.');
            }
        }

        return array_values($rows->map(static fn (Comment $row): string => $row->id)->all());
    }

    /**
     * @param  Identity  $identity
     * @return Builder<Comment>
     */
    private function comments(array $identity): Builder
    {
        $query = $this->boundary->query(CommentModelRegistry::query()->useWritePdo(), 'comments.comments')
            ->withTrashed()->where('commentable_identity_hash', $identity['hash']);
        CommentBatchOwnerAdmission::exact($query, 'commentable_type', $identity['type']);
        CommentBatchOwnerAdmission::exact($query, 'commentable_id', $identity['id']);

        return $query;
    }
}
