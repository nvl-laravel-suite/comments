<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Services\EloquentFilterApplier;
use Nvl\Tenancy\Services\TenantBoundary;

/**
 * Lists the moderation queue only after the privileged capability check.
 */
final readonly class ListModerationCommentsAction
{
    public function __construct(
        private CommentAccessService $access,
        private EloquentFilterApplier $filters,
        private CommentQueryScope $queryScope,
        private CommentTargetLocator $targets,
        private TenantBoundary $boundary,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Comment>
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
            context: ['operation' => 'list'],
        );
        $maximum = CommentsConfiguration::positiveInteger('comments.pagination.maximum', 100);
        $perPage ??= CommentsConfiguration::positiveInteger('comments.pagination.default', 25);
        $filterSet ??= FilterSet::none();
        $actionableStatusHashes = array_map(
            static fn (string $status): string => CommentIdentity::value(
                'comment-status',
                $status,
            ),
            $this->actionableStatuses(),
        );
        $query = $this->boundary->query(Comment::query(), 'comments.comments')
            ->withTrashed()
            ->where(
                'commentable_identity_hash',
                CommentIdentity::pair($identity['type'], $identity['id']),
            );
        $this->queryScope->scopeComments(
            $query,
            $actor,
            $target,
            CommentAudience::Management,
            CommentAbility::Moderate,
        );
        $query
            ->withMax('reports as last_reported_at', 'created_at')
            ->where(
                static fn (Builder $query): Builder => $query
                    ->whereIn('status_hash', $actionableStatusHashes)
                    ->orWhere('open_report_count', '>', 0),
            );
        $this->filters->apply($query, $filterSet, Comment::managementFilterSchema());

        return $query->paginate(max(1, min($maximum, $perPage)));
    }

    /**
     * Return configured moderation statuses that still require action.
     *
     * @return list<string>
     */
    private function actionableStatuses(): array
    {
        $configured = config('comments.moderation.actionable_statuses', [
            CommentStatus::Pending->value,
            CommentStatus::Spam->value,
        ]);

        if (! is_array($configured)) {
            return [CommentStatus::Pending->value, CommentStatus::Spam->value];
        }

        $allowed = array_column(CommentStatus::cases(), 'value');
        $statuses = [];

        foreach ($configured as $status) {
            if (is_string($status)
                && in_array($status, $allowed, true)
                && ! in_array($status, $statuses, true)) {
                $statuses[] = $status;
            }
        }

        return $statuses === []
            ? [CommentStatus::Pending->value, CommentStatus::Spam->value]
            : $statuses;
    }
}
