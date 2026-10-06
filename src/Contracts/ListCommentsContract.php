<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Filterable\Data\FilterSet;

/**
 * Defines the supported list comments workflow.
 *
 * @api
 */
interface ListCommentsContract
{
    /**
     * @return LengthAwarePaginator<int, Comment>
     */
    public function execute(
        Model $target,
        CommentActorData $actor,
        FilterSet $filterSet,
        ?int $perPage = null,
        CommentAudience $audience = CommentAudience::Public,
    ): LengthAwarePaginator;
}
