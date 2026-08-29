<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\DeleteCommentData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentChangeOperation;
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
 * Soft-deletes a comment while preserving its reply thread and audit history.
 */
final readonly class DeleteCommentAction
{
    /**
     * Create the comment deletion action.
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
     * Soft-delete a comment when its revision and actor authorization are current.
     */
    public function execute(
        Comment|string $comment,
        DeleteCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): bool {
        $commentId = $comment instanceof Comment ? $comment->id : $comment;
        $this->guard->expectedRevision($data->expectedRevision);
        $resolved = $this->reads->resolveById(
            $commentId,
            $actor,
            $audience,
            CommentAbility::Delete,
        );
        $lockIds = array_values(array_filter([$resolved->id, $resolved->parent_id]));

        return $this->mutationLock->executeMany(
            $lockIds,
            function () use ($actor, $audience, $commentId, $data, $lockIds): bool {
                return DB::connection((new Comment)->getConnectionName())
                    ->transaction(function () use (
                        $actor,
                        $audience,
                        $commentId,
                        $data,
                        $lockIds,
                    ): bool {
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
                            CommentAbility::Delete,
                        );
                        $target = $this->targets->locate($comment);
                        $this->access->authorize(
                            CommentAbility::Delete,
                            $actor,
                            $comment,
                            $target,
                            $audience,
                            asNotFound: $audience !== CommentAudience::Management,
                        );

                        if ($comment->trashed()) {
                            throw new InvalidCommentLifecycleException(
                                'Only an active comment may be deleted.',
                            );
                        }

                        if ($comment->revision !== $data->expectedRevision) {
                            throw StaleCommentException::forComment($comment->id);
                        }

                        if (! $comment->forceFill([
                            'revision' => $comment->revision + 1,
                            'deleted_by_type' => $actor->type,
                            'deleted_by' => $actor->id,
                        ])->save()) {
                            throw new InvalidCommentLifecycleException(
                                'The comment deletion audit could not be saved.',
                            );
                        }

                        if (! $comment->delete()) {
                            throw new InvalidCommentLifecycleException(
                                'The comment could not be deleted.',
                            );
                        }

                        $this->metadataIndex->delete($comment);

                        $parent = $comment->parent_id === null
                            ? null
                            : $lockedComments->get($comment->parent_id);

                        if ($parent instanceof Comment
                            && $parent->reply_count > 0
                            && $parent->decrement('reply_count') !== 1) {
                            throw new InvalidCommentLifecycleException(
                                'The parent comment reply counter could not be updated.',
                            );
                        }

                        $comment->refresh();
                        CommentChanged::dispatch(
                            $comment->id,
                            $comment->commentable_type,
                            $comment->commentable_id,
                            CommentChangeOperation::Deleted,
                            $comment->revision,
                            $actor,
                        );

                        return true;
                    }, attempts: CommentsConfiguration::positiveInteger(
                        'comments.transactions.attempts',
                        3,
                    ));
            },
        );
    }
}
