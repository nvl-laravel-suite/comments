<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\ModerateCommentData;
use Nvl\Comments\Models\Comment;

/**
 * Defines the supported moderate comment workflow.
 *
 * @api
 */
interface ModerateCommentContract
{
    /**
     * Apply an authorized moderation transition to a current comment revision.
     */
    public function execute(
        Comment|string $comment,
        ModerateCommentData $data,
        CommentActorData $actor,
    ): Comment;
}
