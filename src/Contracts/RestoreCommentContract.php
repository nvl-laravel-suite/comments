<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\RestoreCommentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;

/**
 * Defines the supported restore comment workflow.
 *
 * @api
 */
interface RestoreCommentContract
{
    /**
     * Restore a deleted comment under authorization and optimistic locking.
     */
    public function execute(
        Comment|string $comment,
        RestoreCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment;
}
