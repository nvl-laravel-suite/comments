<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Exceptions\CommentTargetNotFoundException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Services\EloquentFilterApplier;

/**
 * Lists one target's thread through a fixed filter/sort allowlist.
 */
final readonly class ListCommentsAction
{
    public function __construct(
        private CommentReadService $reads,
        private EloquentFilterApplier $filters,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Comment>
     */
    public function execute(
        Model $target,
        CommentActorData $actor,
        FilterSet $filterSet,
        ?int $perPage = null,
        CommentAudience $audience = CommentAudience::Public,
    ): LengthAwarePaginator {
        $identifier = $target->getKey();

        if (! is_int($identifier) && ! is_string($identifier)) {
            throw new InvalidArgumentException('Comment targets require scalar identifiers.');
        }

        $target = (new ($target::class))->newQuery()->find($identifier)
            ?? throw CommentTargetNotFoundException::forIdentifier(
                $target->getMorphClass(),
                (string) $identifier,
            );
        $maximum = $this->maximumPerPage($filterSet);
        $perPage ??= CommentsConfiguration::positiveInteger('comments.pagination.default', 25);
        $query = $this->reads->query($target, $actor, $audience);
        $this->filters->apply($query, $filterSet, Comment::filterSchema());

        return $query->paginate(max(1, min($maximum, $perPage)));
    }

    /**
     * Resolve the configured page cap for roots or a filtered reply tree.
     */
    private function maximumPerPage(FilterSet $filterSet): int
    {
        $maximum = CommentsConfiguration::positiveInteger('comments.pagination.maximum', 100);

        foreach ($filterSet->filters as $filter) {
            if ($filter->alias === 'root'
                && $filter->operator === FilterOperator::Equals
                && $filter->value !== null) {
                return min(
                    $maximum,
                    CommentsConfiguration::positiveInteger(
                        'comments.threading.maximum_replies_per_page',
                        100,
                    ),
                );
            }
        }

        return $maximum;
    }
}
