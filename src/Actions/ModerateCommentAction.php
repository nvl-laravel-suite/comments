<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\ModerateCommentData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Exceptions\InvalidCommentLifecycleException;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Exceptions\StaleCommentException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentMutationLock;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Services\CommentWorkflowGuard;
use Nvl\Comments\Support\CommentsConfiguration;

/**
 * Applies an explicit moderation state and pin transition.
 */
final readonly class ModerateCommentAction
{
    public function __construct(
        private CommentAccessService $access,
        private CommentWorkflowGuard $guard,
        private CommentMutationLock $mutationLock,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
    ) {}

    /**
     * Apply an authorized moderation transition to a current comment revision.
     */
    public function execute(
        Comment|string $comment,
        ModerateCommentData $data,
        CommentActorData $actor,
    ): Comment {
        $commentId = $comment instanceof Comment ? $comment->id : $comment;
        $this->guard->moderation($data);

        return $this->mutationLock->execute(
            $commentId,
            fn (): Comment => DB::connection((new Comment)->getConnectionName())
                ->transaction(function () use ($actor, $commentId, $data): Comment {
                    $comment = $this->reads->resolveById(
                        $commentId,
                        $actor,
                        CommentAudience::Management,
                        CommentAbility::Moderate,
                        withTrashed: true,
                        lockForUpdate: true,
                    );
                    $target = $this->targets->locate($comment);
                    $this->access->authorize(
                        CommentAbility::Moderate,
                        $actor,
                        $comment,
                        $target,
                        CommentAudience::Management,
                    );

                    if ($comment->anonymized_at !== null) {
                        throw new InvalidCommentLifecycleException(
                            'An anonymized comment cannot be moderated.',
                        );
                    }

                    if ($comment->revision !== $data->expectedRevision) {
                        throw StaleCommentException::forComment($comment->id);
                    }

                    $isPinned = $data->pinned ?? $comment->is_pinned;

                    if ($comment->status === $data->status
                        && $comment->is_pinned === $isPinned
                        && $comment->moderated_by_type === $actor->type
                        && $comment->moderated_by === $actor->id
                        && $comment->moderation_reason === $data->reason
                        && $comment->moderated_at !== null) {
                        return Comment::query()
                            ->withTrashed()
                            ->findOrFail($comment->id);
                    }

                    $attributes = [
                        'status' => $data->status,
                        'is_pinned' => $isPinned,
                        'revision' => $comment->revision + 1,
                        'moderated_by_type' => $actor->type,
                        'moderated_by' => $actor->id,
                        'moderation_reason' => $data->reason,
                        'moderated_at' => now(),
                    ];

                    if (! $comment->fill($attributes)->save()) {
                        throw new InvalidCommentMutationException(
                            'The comment moderation could not be saved.',
                        );
                    }

                    CommentChanged::dispatch(
                        $comment->id,
                        $comment->commentable_type,
                        $comment->commentable_id,
                        CommentChangeOperation::Moderated,
                        $comment->revision,
                        $actor,
                    );

                    return Comment::query()
                        ->withTrashed()
                        ->findOrFail($comment->id);
                }, attempts: CommentsConfiguration::positiveInteger(
                    'comments.transactions.attempts',
                    3,
                )),
        );
    }
}
