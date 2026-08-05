<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentReport;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Services\EloquentFilterApplier;

/**
 * Lists one comment's reports after target-aware moderator authorization.
 */
final readonly class ListCommentReportsAction
{
    public function __construct(
        private CommentAccessService $access,
        private EloquentFilterApplier $filters,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
    ) {}

    /**
     * Return a bounded newest-first report history for one comment.
     *
     * @return LengthAwarePaginator<int, CommentReport>
     */
    public function execute(
        Comment|string $comment,
        CommentActorData $actor,
        ?int $perPage = null,
        ?FilterSet $filterSet = null,
    ): LengthAwarePaginator {
        $comment = $this->reads->resolveById(
            $comment,
            $actor,
            CommentAudience::Management,
            CommentAbility::Moderate,
        );
        $target = $this->targets->locate($comment);
        $this->access->authorize(
            CommentAbility::Moderate,
            $actor,
            $comment,
            $target,
            CommentAudience::Management,
            context: ['operation' => 'list_reports'],
        );
        $maximum = CommentsConfiguration::positiveInteger('comments.pagination.maximum', 100);
        $perPage ??= CommentsConfiguration::positiveInteger('comments.pagination.default', 25);
        $query = CommentReport::query()
            ->with('comment')
            ->where('comment_id', $comment->id);
        $this->filters->apply(
            $query,
            $filterSet ?? FilterSet::none(),
            CommentReport::filterSchema(),
        );

        return $query->paginate(max(1, min($maximum, $perPage)));
    }
}
