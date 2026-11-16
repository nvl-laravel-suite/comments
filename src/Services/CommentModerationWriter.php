<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Support\Facades\DB;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Exceptions\InvalidCommentLifecycleException;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Exceptions\StaleCommentException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/**
 * Applies an explicit moderation state and pin transition.
 *
 * @internal
 */
final readonly class CommentModerationWriter
{
    public function __construct(
        private CommentAccessService $access,
        private CommentWorkflowGuard $guard,
        private CommentMutationLock $mutationLock,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
        private OwnershipBoundary $boundary,
        private DomainEventDispatcher $domainEvents,
    ) {}

    /**
     * Apply an authorized moderation transition to a current comment revision.
     * A request that only sets the flagged status is authorized as Flag; every
     * other transition, including any pin change, requires Moderate.
     *
     * @param  array<string, mixed>  $data
     */
    public function moderate(
        Comment|string $comment,
        array $data,
        CommentActor $actor,
    ): Comment {
        $data = $this->normalizeModeration($data);
        $commentId = $comment instanceof Comment ? $comment->id : $comment;
        $this->guard->moderation($data);
        $ability = $data['status'] === CommentStatus::Flagged && $data['pinned'] === null
            ? CommentAbility::Flag
            : CommentAbility::Moderate;

        return $this->mutationLock->execute(
            $commentId,
            fn (): Comment => DB::connection((CommentModelRegistry::newInstance())->getConnectionName())
                ->transaction(function () use ($ability, $actor, $commentId, $data): Comment {
                    $comment = $this->reads->resolveById(
                        $commentId,
                        $actor,
                        CommentAudience::Management,
                        $ability,
                        withTrashed: true,
                        lockForUpdate: true,
                    );
                    $target = $this->targets->locate($comment);
                    $this->access->authorize(
                        $ability,
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

                    if ($comment->revision !== $data['expected_revision']) {
                        throw StaleCommentException::forComment($comment->id);
                    }

                    $isPinned = $data['pinned'] ?? $comment->is_pinned;

                    if ($comment->status === $data['status']
                        && $comment->is_pinned === $isPinned
                        && $comment->moderated_by_type === $actor->type
                        && $comment->moderated_by === $actor->id
                        && $comment->moderation_reason === $data['reason']
                        && $comment->moderated_at !== null) {
                        return $this->boundary->query(CommentModelRegistry::query(), 'comments.comments')
                            ->withTrashed()
                            ->findOrFail($comment->id);
                    }

                    $moderatedAt = now();
                    $attributes = [
                        'status' => $data['status'],
                        'is_pinned' => $isPinned,
                        'revision' => $comment->revision + 1,
                        'moderated_by_type' => $actor->type,
                        'moderated_by' => $actor->id,
                        'moderation_reason' => $data['reason'],
                        'moderated_at' => $moderatedAt,
                    ];
                    if ($comment->status !== CommentStatus::Approved && $data['status'] === CommentStatus::Approved) {
                        $attributes['approved_by_type'] = $actor->type;
                        $attributes['approved_by'] = $actor->id;
                        $attributes['approved_at'] = $moderatedAt;
                    }
                    if ($comment->is_pinned !== $isPinned) {
                        $attributes['pinned_by_type'] = $isPinned ? $actor->type : null;
                        $attributes['pinned_by'] = $isPinned ? $actor->id : null;
                        $attributes['pinned_at'] = $isPinned ? $moderatedAt : null;
                    }

                    if (! $comment->fill($attributes)->save()) {
                        throw new InvalidCommentMutationException(
                            'The comment moderation could not be saved.',
                        );
                    }

                    $this->domainEvents->dispatch(new CommentChanged(
                        $comment->id,
                        $comment->commentable_type,
                        $comment->commentable_id,
                        CommentChangeOperation::Moderated,
                        $comment->revision,
                        $actor,
                    ), $comment->getConnection());

                    return $this->boundary->query(CommentModelRegistry::query(), 'comments.comments')
                        ->withTrashed()
                        ->findOrFail($comment->id);
                }, attempts: CommentsConfiguration::positiveInteger(
                    'nvl-comments.transactions.attempts',
                    3,
                )),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{status: CommentStatus, expected_revision: int, reason: ?string, pinned: ?bool}
     */
    private function normalizeModeration(array $attributes): array
    {
        if (array_diff(array_keys($attributes), ['status', 'expected_revision', 'reason', 'pinned']) !== []
            || ! is_int($attributes['expected_revision'] ?? null)
            || $attributes['expected_revision'] < 1
            || (array_key_exists('reason', $attributes)
                && $attributes['reason'] !== null
                && ! is_string($attributes['reason']))
            || (array_key_exists('pinned', $attributes)
                && $attributes['pinned'] !== null
                && ! is_bool($attributes['pinned']))) {
            throw new InvalidCommentMutationException('Comment moderation attributes are invalid.');
        }

        $status = $attributes['status'] ?? null;
        if (is_string($status)) {
            $status = CommentStatus::tryFrom($status);
        }
        if (! $status instanceof CommentStatus) {
            throw new InvalidCommentMutationException('Comment moderation status is invalid.');
        }

        return [
            'expected_revision' => $attributes['expected_revision'],
            'status' => $status,
            'reason' => $attributes['reason'] ?? null,
            'pinned' => $attributes['pinned'] ?? null,
        ];
    }
}
