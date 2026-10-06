<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\ReportCommentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentReport;

/**
 * Defines the supported report comment workflow.
 *
 * @api
 */
interface ReportCommentContract
{
    /**
     * Create or refresh one identified actor's report for a comment.
     */
    public function execute(
        Comment|string $comment,
        ReportCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): CommentReport;
}
