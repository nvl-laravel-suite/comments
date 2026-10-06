<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Models\CommentReport;
use Nvl\Filterable\Data\FilterSet;

/**
 * Defines the supported list target comment reports workflow.
 *
 * @api
 */
interface ListTargetCommentReportsContract
{
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
    ): LengthAwarePaginator;
}
