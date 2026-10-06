<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\UpdateRichCommentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;

/**
 * Defines the supported update rich comment workflow.
 *
 * @api
 */
interface UpdateRichCommentContract
{
    /**
     * Replace authorized rich content with optimistic concurrency protection.
     */
    public function execute(
        Comment|string $comment,
        UpdateRichCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment;
}
