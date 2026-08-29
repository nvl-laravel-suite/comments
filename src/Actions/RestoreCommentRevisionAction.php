<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\RestoreCommentRevisionData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Exceptions\InvalidCommentLifecycleException;
use Nvl\Comments\Exceptions\StaleCommentException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentLifecycleGuard;
use Nvl\Comments\Services\CommentMetadataGuard;
use Nvl\Comments\Services\CommentMetadataIndexWriter;
use Nvl\Comments\Services\CommentMutationLock;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Support\CommentsConfiguration;

/**
 * Restores historical comment content as a new optimistic revision.
 */
final readonly class RestoreCommentRevisionAction
{
    /**
     * Create the historical revision restoration action.
     */
    public function __construct(
        private CommentAccessService $access,
        private CommentLifecycleGuard $guard,
        private CommentMutationLock $mutationLock,
        private CommentMetadataGuard $metadataGuard,
        private CommentMetadataIndexWriter $metadataIndex,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
    ) {}

    /**
     * Apply one owned historical snapshot while preserving the current snapshot.
     */
    public function execute(
        Comment|string $comment,
        CommentRevision|string $revision,
        RestoreCommentRevisionData $data,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment {
        $commentId = $comment instanceof Comment ? $comment->id : $comment;
        $revisionId = $revision instanceof CommentRevision ? $revision->id : $revision;
        $this->guard->expectedRevision($data->expectedRevision);

        return $this->mutationLock->execute(
            $commentId,
            function () use (
                $actor,
                $audience,
                $commentId,
                $data,
                $revisionId,
            ): Comment {
                return DB::connection((new Comment)->getConnectionName())
                    ->transaction(function () use (
                        $actor,
                        $audience,
                        $commentId,
                        $data,
                        $revisionId,
                    ): Comment {
                        $comment = $this->reads->resolveById(
                            $commentId,
                            $actor,
                            $audience,
                            CommentAbility::RestoreRevision,
                            withTrashed: true,
                            lockForUpdate: true,
                        );
                        $target = $this->targets->locate($comment);
                        $this->access->authorize(
                            CommentAbility::RestoreRevision,
                            $actor,
                            $comment,
                            $target,
                            $audience,
                            asNotFound: $audience !== CommentAudience::Management,
                        );

                        if ($comment->trashed() || $comment->anonymized_at !== null) {
                            throw new InvalidCommentLifecycleException(
                                'Historical content may only be restored to an active, non-anonymized comment.',
                            );
                        }

                        if ($comment->revision !== $data->expectedRevision) {
                            throw StaleCommentException::forComment($comment->id);
                        }

                        $revision = CommentRevision::query()
                            ->where('comment_id', $comment->id)
                            ->whereKey($revisionId)
                            ->lockForUpdate()
                            ->firstOrFail();

                        if ($revision->revision >= $comment->revision) {
                            throw new InvalidCommentLifecycleException(
                                'Only a historical comment revision may be restored.',
                            );
                        }

                        $currentMetadata = $this->metadataGuard->normalize(
                            $comment->metadata ?? [],
                        );
                        $restoredMetadata = $this->metadataGuard->normalize(
                            $revision->metadata ?? [],
                        );

                        $currentRevision = CommentRevision::query()->create([
                            'comment_id' => $comment->id,
                            'revision' => $comment->revision,
                            'body' => $comment->body,
                            'format' => $comment->format,
                            'locale' => $comment->locale,
                            'tags' => $comment->tags,
                            'metadata' => $currentMetadata,
                            'edited_by_type' => $actor->type,
                            'edited_by' => $actor->id,
                        ]);

                        if (! $currentRevision->exists) {
                            throw new InvalidCommentLifecycleException(
                                'The current comment revision could not be preserved.',
                            );
                        }

                        $configuredStatus = config(
                            'comments.moderation.edited_status',
                            CommentStatus::Pending->value,
                        );
                        $status = is_string($configuredStatus)
                            ? CommentStatus::tryFrom($configuredStatus)
                            : null;
                        $saved = $comment->forceFill([
                            'body' => $revision->body,
                            'format' => $revision->format,
                            'locale' => $revision->locale,
                            'tags' => $revision->tags,
                            'metadata' => $restoredMetadata,
                            'status' => $status ?? $comment->status,
                            'revision' => $comment->revision + 1,
                            'edited_at' => now(),
                        ])->save();

                        if (! $saved) {
                            throw new InvalidCommentLifecycleException(
                                'The historical comment revision could not be restored.',
                            );
                        }

                        $this->metadataIndex->synchronize($comment, $restoredMetadata);

                        $comment->refresh();
                        CommentChanged::dispatch(
                            $comment->id,
                            $comment->commentable_type,
                            $comment->commentable_id,
                            CommentChangeOperation::RevisionRestored,
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
