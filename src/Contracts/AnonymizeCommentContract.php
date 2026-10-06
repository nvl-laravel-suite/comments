<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\AnonymizeCommentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;

/**
 * Defines the supported anonymize comment workflow.
 *
 * @api
 */
interface AnonymizeCommentContract
{
    /**
     * Clear identifying content and associations exactly once.
     */
    public function execute(
        Comment|string $comment,
        AnonymizeCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment;
}
