<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\UpdateCommentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;

/**
 * Defines the supported update comment workflow.
 *
 * @api
 */
interface UpdateCommentContract
{
    /**
     * Replace editable content while preserving the previous revision.
     */
    public function execute(
        Comment|string $comment,
        UpdateCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment;
}
