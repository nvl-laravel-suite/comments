<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentReport;
use Nvl\Filterable\Data\FilterSet;

/**
 * Defines the supported list comment reports workflow.
 *
 * @api
 */
interface ListCommentReportsContract
{
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
    ): LengthAwarePaginator;
}
