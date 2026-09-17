<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentReport;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Services\EloquentFilterApplier;
use Nvl\Tenancy\Services\TenantBoundary;

/**
 * Lists one target's actionable reports including reports on soft-deleted comments.
 */
final readonly class ListTargetCommentReportsAction
{
    public function __construct(
        private CommentAccessService $access,
        private EloquentFilterApplier $filters,
        private CommentQueryScope $queryScope,
        private CommentTargetLocator $targets,
        private TenantBoundary $boundary,
    ) {}

    /**
     * Return a bounded target-scoped report queue after moderator authorization.
     *
     * @return LengthAwarePaginator<int, CommentReport>
     */
    public function execute(
        Model $target,
        CommentActorData $actor,
        ?int $perPage = null,
        ?FilterSet $filterSet = null,
    ): LengthAwarePaginator {
        $target = $this->targets->reload($target);
        $identity = CommentTargetIdentifier::canonical($target);
        $this->access->authorize(
            CommentAbility::Moderate,
            $actor,
            target: $target,
            audience: CommentAudience::Management,
            context: ['operation' => 'list_target_reports'],
        );
        $maximum = CommentsConfiguration::positiveInteger('comments.pagination.maximum', 100);
        $perPage ??= CommentsConfiguration::positiveInteger('comments.pagination.default', 25);
        $filterSet ??= FilterSet::none();
        $targetHash = CommentIdentity::pair($identity['type'], $identity['id']);
        $commentQuery = $this->boundary->query(Comment::query(), 'comments.comments')
            ->withTrashed()
            ->where('commentable_identity_hash', $targetHash);
        $this->queryScope->scopeComments(
            $commentQuery,
            $actor,
            $target,
            CommentAudience::Management,
            CommentAbility::Moderate,
        );
        $query = $this->boundary->query(CommentReport::query(), 'comments.reports')
            ->with('comment')
            ->whereIn('comment_id', $commentQuery->select('id'));

        if (! $this->hasStatusFilter($filterSet)) {
            $query->where(
                'status_hash',
                CommentIdentity::value('report-status', CommentReportStatus::Open),
            );
        }

        $this->filters->apply($query, $filterSet, CommentReport::filterSchema());

        return $query->paginate(max(1, min($maximum, $perPage)));
    }

    /**
     * Determine whether the caller explicitly selected report statuses.
     */
    private function hasStatusFilter(FilterSet $filterSet): bool
    {
        foreach ($filterSet->filters as $filter) {
            if ($filter->alias === 'status') {
                return true;
            }
        }

        return false;
    }
}
