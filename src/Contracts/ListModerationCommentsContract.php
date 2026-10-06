<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Models\Comment;
use Nvl\Filterable\Data\FilterSet;

/**
 * Defines the supported list moderation comments workflow.
 *
 * @api
 */
interface ListModerationCommentsContract
{
    /**
     * @return LengthAwarePaginator<int, Comment>
     */
    public function execute(
        Model $target,
        CommentActorData $actor,
        ?int $perPage = null,
        ?FilterSet $filterSet = null,
    ): LengthAwarePaginator;
}
