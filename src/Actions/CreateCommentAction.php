<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Exceptions\CommentIdempotencyConflictException;
use Nvl\Comments\Exceptions\InvalidCommentLifecycleException;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentContentGuard;
use Nvl\Comments\Services\CommentIdempotencyDigest;
use Nvl\Comments\Services\CommentLifecycleGuard;
use Nvl\Comments\Services\CommentMutationLock;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Comments\Support\CommentTargetIdentifier;

/**
 * Creates an idempotent root comment or bounded reply atomically.
 */
final readonly class CreateCommentAction
{
    /**
     * Create the comment creation action.
     */
    public function __construct(
        private CommentAccessService $access,
        private CommentContentGuard $guard,
        private CommentIdempotencyDigest $idempotency,
        private CommentLifecycleGuard $lifecycle,
        private CommentMutationLock $mutationLock,
        private CommentReadService $reads,
    ) {}

    /**
     * Create a root comment or an authorized reply for a persisted target.
     */
    public function execute(
        Model $target,
        CreateCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment {
        $idempotencyKey = $this->lifecycle->idempotencyKey($data->idempotencyKey);
        $targetPrototype = new ($target::class);
        $targetLookupKey = CommentTargetIdentifier::lookupKey(
            $targetPrototype,
            $target->getKey(),
        );
        $commentConnectionName = (new Comment)->getConnectionName();
        $targetConnectionName = $targetPrototype->getConnectionName();
        $sharesConnection = DB::connection($commentConnectionName)->getName()
            === DB::connection($targetConnectionName)->getName();

        if (! $sharesConnection
            && DB::connection($targetConnectionName)->transactionLevel() > 0) {
            throw new InvalidCommentMutationException(
                'Cross-connection comment creation must run after the target transaction commits.',
            );
        }

        $lockIdentities = array_values(array_filter([
            $idempotencyKey === null ? null : "idempotency:{$idempotencyKey}",
            $data->parentId,
        ]));

        return $this->mutationLock->executeMany(
            $lockIdentities,
            function () use (
                $actor,
                $audience,
                $commentConnectionName,
                $data,
                $idempotencyKey,
                $sharesConnection,
                $targetLookupKey,
                $targetPrototype,
            ): Comment {
                $idempotencyHash = null;

                try {
                    return DB::connection($commentConnectionName)
                        ->transaction(function () use (
                            $actor,
                            $audience,
                            $data,
                            $idempotencyKey,
                            &$idempotencyHash,
                            $sharesConnection,
                            $targetLookupKey,
                            $targetPrototype,
                        ): Comment {
                            $targetQuery = $targetPrototype->newQuery();

                            if ($sharesConnection) {
                                $targetQuery->lockForUpdate();
                            }

                            $canonicalTarget = $targetQuery->find($targetLookupKey);

                            if (! $canonicalTarget instanceof Model) {
                                throw new InvalidCommentMutationException(
                                    'Comments require a target that still exists.',
                                );
                            }

                            $targetIdentity = CommentTargetIdentifier::canonical($canonicalTarget);

                            if ($idempotencyKey !== null) {
                                $existing = Comment::query()
                                    ->withTrashed()
                                    ->where('idempotency_key', $idempotencyKey)
                                    ->lockForUpdate()
                                    ->first();

                                if ($existing instanceof Comment) {
                                    $idempotencyHash = $this->idempotency->make(
                                        $targetIdentity['type'],
                                        $targetIdentity['id'],
                                        $data->parentId,
                                        $data->parentId === null
                                            ? $data->visibility
                                            : $existing->visibility,
                                        $data,
                                        $actor,
                                    );

                                    return $this->authorizedReplay(
                                        $existing,
                                        $idempotencyHash,
                                        $canonicalTarget,
                                        $data,
                                        $actor,
                                        $audience,
                                    );
                                }
                            }

                            $this->guard->create($data);
                            $parent = $data->parentId === null
                                ? null
                                : $this->reads->resolve(
                                    $canonicalTarget,
                                    $data->parentId,
                                    $actor,
                                    $audience,
                                    CommentAbility::Reply,
                                    lockForUpdate: true,
                                );

                            $visibility = $parent === null
                                ? $data->visibility
                                : $parent->visibility;

                            if ($parent === null
                                && $audience === CommentAudience::Public
                                && $visibility !== CommentVisibility::Public) {
                                throw new InvalidCommentMutationException(
                                    'Public comment creation is restricted to public visibility.',
                                );
                            }

                            if ($idempotencyKey !== null) {
                                $idempotencyHash = $this->idempotency->make(
                                    $targetIdentity['type'],
                                    $targetIdentity['id'],
                                    $parent?->id,
                                    $visibility,
                                    $data,
                                    $actor,
                                );
                            }

                            if ($parent?->trashed() === true || $parent?->anonymized_at !== null) {
                                throw new InvalidCommentLifecycleException(
                                    'Replies require an active, non-anonymized parent comment.',
                                );
                            }

                            if ($parent === null) {
                                $this->access->authorize(
                                    CommentAbility::Create,
                                    $actor,
                                    target: $canonicalTarget,
                                    audience: $audience,
                                    context: ['visibility' => $data->visibility->value],
                                );
                            } else {
                                $this->access->authorize(
                                    CommentAbility::View,
                                    $actor,
                                    $parent,
                                    $canonicalTarget,
                                    $audience,
                                    asNotFound: $audience !== CommentAudience::Management,
                                );
                                $this->access->authorize(
                                    CommentAbility::Reply,
                                    $actor,
                                    $parent,
                                    $canonicalTarget,
                                    $audience,
                                    asNotFound: $audience !== CommentAudience::Management,
                                    context: [
                                        'requested_visibility' => $data->visibility->value,
                                        'inherited_visibility' => $parent->visibility->value,
                                    ],
                                );
                            }

                            $depth = $parent === null ? 0 : $parent->depth + 1;
                            $maximumDepth = CommentsConfiguration::positiveInteger(
                                'comments.threading.maximum_depth',
                                6,
                            );

                            if ($depth > $maximumDepth) {
                                throw new InvalidCommentMutationException(
                                    "Comment nesting exceeds the configured depth of {$maximumDepth}.",
                                );
                            }

                            $configuredStatus = config('comments.moderation.new_status', 'pending');
                            $status = is_string($configuredStatus)
                                ? CommentStatus::tryFrom($configuredStatus)
                                : null;
                            $comment = Comment::query()->create([
                                'commentable_type' => $targetIdentity['type'],
                                'commentable_id' => $targetIdentity['id'],
                                'root_id' => $parent === null ? null : ($parent->root_id ?? $parent->id),
                                'parent_id' => $parent?->id,
                                'depth' => $depth,
                                'actor_type' => $actor->type,
                                'actor_id' => $actor->id,
                                'idempotency_key' => $idempotencyKey,
                                'idempotency_hash' => $idempotencyHash,
                                'body' => $data->body,
                                'format' => $data->format,
                                'locale' => $data->locale,
                                'status' => $status ?? CommentStatus::Pending,
                                'visibility' => $visibility,
                                'tags' => $data->tags,
                                'metadata' => $data->metadata,
                            ]);

                            if (! $comment->exists) {
                                throw new InvalidCommentMutationException(
                                    'The comment could not be created.',
                                );
                            }

                            if ($parent !== null
                                && $parent->increment('reply_count') !== 1) {
                                throw new InvalidCommentMutationException(
                                    'The parent comment reply counter could not be updated.',
                                );
                            }

                            CommentChanged::dispatch(
                                $comment->id,
                                $comment->commentable_type,
                                $comment->commentable_id,
                                CommentChangeOperation::Created,
                                $comment->revision,
                                $actor,
                            );

                            return $comment;
                        }, attempts: CommentsConfiguration::positiveInteger(
                            'comments.transactions.attempts',
                            3,
                        ));
                } catch (UniqueConstraintViolationException $exception) {
                    if ($idempotencyKey === null || $idempotencyHash === null) {
                        throw $exception;
                    }

                    $existing = Comment::query()
                        ->withTrashed()
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if (! $existing instanceof Comment) {
                        throw $exception;
                    }

                    $canonicalTarget = $targetPrototype->newQuery()->find($targetLookupKey);

                    if (! $canonicalTarget instanceof Model) {
                        throw new InvalidCommentMutationException(
                            'Comments require a target that still exists.',
                        );
                    }

                    return $this->authorizedReplay(
                        $existing,
                        $idempotencyHash,
                        $canonicalTarget,
                        $data,
                        $actor,
                        $audience,
                    );
                }
            },
        );
    }

    /**
     * Verify an exact keyed request against current target and participation access.
     */
    private function authorizedReplay(
        Comment $comment,
        string $idempotencyHash,
        Model $target,
        CreateCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience,
    ): Comment {
        $comment = $this->replay($comment, $idempotencyHash);

        if ($comment->parent_id === null) {
            $this->access->authorize(
                CommentAbility::Create,
                $actor,
                target: $target,
                audience: $audience,
                context: ['visibility' => $comment->visibility->value],
                asNotFound: $audience !== CommentAudience::Management,
            );

            return $comment;
        }

        $parent = $this->reads->resolve(
            $target,
            $comment->parent_id,
            $actor,
            $audience,
            CommentAbility::Reply,
            withTrashed: true,
        );
        $this->access->authorize(
            CommentAbility::View,
            $actor,
            $parent,
            $target,
            $audience,
            asNotFound: $audience !== CommentAudience::Management,
        );
        $this->access->authorize(
            CommentAbility::Reply,
            $actor,
            $parent,
            $target,
            $audience,
            context: [
                'requested_visibility' => $data->visibility->value,
                'inherited_visibility' => $comment->visibility->value,
            ],
            asNotFound: $audience !== CommentAudience::Management,
        );

        return $comment;
    }

    /**
     * Return an exact idempotent replay or reject a conflicting payload.
     */
    private function replay(Comment $comment, string $idempotencyHash): Comment
    {
        if ($comment->idempotency_hash === null
            || ! hash_equals($comment->idempotency_hash, $idempotencyHash)) {
            throw CommentIdempotencyConflictException::forKey();
        }

        $comment->wasRecentlyCreated = false;

        return $comment;
    }
}
