<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;

/**
 * Defines the supported get comment workflow.
 *
 * @api
 */
interface GetCommentContract
{
    /**
     * Resolve one comment through its canonical target and trusted audience scope.
     */
    public function execute(
        Comment|string $comment,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment;
}
