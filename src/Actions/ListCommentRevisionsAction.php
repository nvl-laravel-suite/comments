<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Support\CommentsConfiguration;

/**
 * Lists one comment's authorized immutable content history.
 */
final readonly class ListCommentRevisionsAction
{
    /**
     * Create the comment revision listing action.
     */
    public function __construct(
        private CommentAccessService $access,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
    ) {}

    /**
     * Return a bounded newest-first revision history.
     *
     * @return LengthAwarePaginator<int, CommentRevision>
     */
    public function execute(
        Comment|string $comment,
        CommentActorData $actor,
        ?int $perPage = null,
        CommentAudience $audience = CommentAudience::Public,
    ): LengthAwarePaginator {
        $comment = $this->reads->resolveById(
            $comment,
            $actor,
            $audience,
            CommentAbility::ViewHistory,
        );
        $target = $this->targets->locate($comment);
        $this->access->authorize(
            CommentAbility::ViewHistory,
            $actor,
            $comment,
            $target,
            $audience,
            asNotFound: $audience !== CommentAudience::Management,
        );
        $maximum = CommentsConfiguration::positiveInteger(
            'comments.pagination.maximum',
            100,
        );
        $perPage ??= CommentsConfiguration::positiveInteger(
            'comments.pagination.default',
            25,
        );

        return CommentRevision::query()
            ->where('comment_id', $comment->id)
            ->orderByDesc('revision')
            ->orderByDesc('id')
            ->paginate(max(1, min($maximum, $perPage)));
    }
}
