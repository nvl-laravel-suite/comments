<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentRevision;

/**
 * Defines the supported list comment revisions workflow.
 *
 * @api
 */
interface ListCommentRevisionsContract
{
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
    ): LengthAwarePaginator;
}
