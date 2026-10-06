<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\DeleteCommentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;

/**
 * Defines the supported delete comment workflow.
 *
 * @api
 */
interface DeleteCommentContract
{
    /**
     * Soft-delete a comment when its revision and actor authorization are current.
     */
    public function execute(
        Comment|string $comment,
        DeleteCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): bool;
}
