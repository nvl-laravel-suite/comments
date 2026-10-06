<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;

/**
 * Defines the supported detach comment media workflow.
 *
 * @api
 */
interface DetachCommentMediaContract
{
    /**
     * Remove one attachment association and treat an absent association as an idempotent no-op.
     */
    public function execute(
        Comment|string $comment,
        string $associationId,
        CommentActorData $actor,
        CommentAudience $audience,
    ): bool;
}
