<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\RestoreCommentData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Exceptions\InvalidCommentLifecycleException;
use Nvl\Comments\Exceptions\StaleCommentException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentLifecycleGuard;
use Nvl\Comments\Services\CommentMetadataIndexWriter;
use Nvl\Comments\Services\CommentMutationLock;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Support\CommentsConfiguration;

/**
 * Restores one soft-deleted comment without reviving an invalid reply branch.
 */
final readonly class RestoreCommentAction
{
    /**
     * Create the comment restoration action.
     */
    public function __construct(
        private CommentAccessService $access,
        private CommentLifecycleGuard $guard,
        private CommentMetadataIndexWriter $metadataIndex,
        private CommentMutationLock $mutationLock,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
    ) {}

    /**
     * Restore a deleted comment under authorization and optimistic locking.
     */
    public function execute(
        Comment|string $comment,
        RestoreCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment {
        $commentId = $comment instanceof Comment ? $comment->id : $comment;
        $this->guard->expectedRevision($data->expectedRevision);
        $resolved = $this->reads->resolveById(
            $commentId,
            $actor,
            $audience,
            CommentAbility::Restore,
        );
        $lockIds = array_values(array_filter([$resolved->id, $resolved->parent_id]));

        return $this->mutationLock->executeMany(
            $lockIds,
            function () use ($actor, $audience, $commentId, $data, $lockIds): Comment {
                return DB::connection((new Comment)->getConnectionName())
                    ->transaction(function () use (
                        $actor,
                        $audience,
                        $commentId,
                        $data,
                        $lockIds,
                    ): Comment {
                        sort($lockIds, SORT_STRING);
                        $lockedComments = Comment::query()
                            ->withTrashed()
                            ->whereKey($lockIds)
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get()
                            ->keyBy('id');
                        $comment = $lockedComments->get($commentId);

                        if (! $comment instanceof Comment) {
                            throw (new ModelNotFoundException)->setModel(
                                Comment::class,
                                [$commentId],
                            );
                        }

                        $this->reads->resolveById(
                            $commentId,
                            $actor,
                            $audience,
                            CommentAbility::Restore,
                        );
                        $target = $this->targets->locate($comment);
                        $this->access->authorize(
                            CommentAbility::Restore,
                            $actor,
                            $comment,
                            $target,
                            $audience,
                            asNotFound: $audience !== CommentAudience::Management,
                        );

                        if ($comment->anonymized_at !== null) {
                            throw new InvalidCommentLifecycleException(
                                'An anonymized comment cannot be restored.',
                            );
                        }

                        if (! $comment->trashed()) {
                            throw new InvalidCommentLifecycleException(
                                'Only a deleted comment may be restored.',
                            );
                        }

                        if ($comment->revision !== $data->expectedRevision) {
                            throw StaleCommentException::forComment($comment->id);
                        }

                        $parent = $comment->parent_id === null
                            ? null
                            : $lockedComments->get($comment->parent_id);

                        if ($comment->parent_id !== null
                            && (! $parent instanceof Comment
                                || $parent->trashed()
                                || $parent->anonymized_at !== null)) {
                            throw new InvalidCommentLifecycleException(
                                'A reply requires an active, non-anonymized parent before restoration.',
                            );
                        }

                        $configuredStatus = config(
                            'comments.moderation.restored_status',
                            CommentStatus::Pending->value,
                        );
                        $status = is_string($configuredStatus)
                            ? CommentStatus::tryFrom($configuredStatus)
                            : null;
                        $comment->forceFill([
                            'status' => $status ?? CommentStatus::Pending,
                            'revision' => $comment->revision + 1,
                            'restored_at' => now(),
                            'restored_by_type' => $actor->type,
                            'restored_by' => $actor->id,
                        ]);

                        if (! $comment->restore()) {
                            throw new InvalidCommentLifecycleException(
                                'The comment could not be restored.',
                            );
                        }

                        $this->metadataIndex->synchronize($comment, $comment->metadata);

                        if ($parent instanceof Comment
                            && $parent->increment('reply_count') !== 1) {
                            throw new InvalidCommentLifecycleException(
                                'The parent comment reply counter could not be updated.',
                            );
                        }

                        $comment->refresh();
                        CommentChanged::dispatch(
                            $comment->id,
                            $comment->commentable_type,
                            $comment->commentable_id,
                            CommentChangeOperation::Restored,
                            $comment->revision,
                            $actor,
                        );

                        return $comment;
                    }, attempts: CommentsConfiguration::positiveInteger(
                        'comments.transactions.attempts',
                        3,
                    ));
            },
        );
    }
}
