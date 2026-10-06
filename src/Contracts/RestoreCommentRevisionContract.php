<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\RestoreCommentRevisionData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentRevision;

/**
 * Defines the supported restore comment revision workflow.
 *
 * @api
 */
interface RestoreCommentRevisionContract
{
    /**
     * Apply one owned historical snapshot while preserving the current snapshot.
     */
    public function execute(
        Comment|string $comment,
        CommentRevision|string $revision,
        RestoreCommentRevisionData $data,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment;
}
