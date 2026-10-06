<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentReaction;

/**
 * Defines the supported set comment reaction workflow.
 *
 * @api
 */
interface SetCommentReactionContract
{
    /**
     * Set one configured reaction to the actor's explicit desired state.
     */
    public function execute(
        Comment|string $comment,
        string $type,
        bool $active,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): ?CommentReaction;
}
