<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentManagementData;
use Nvl\Comments\Data\MemberCommentData;
use Nvl\Comments\Data\PublicCommentData;
use Nvl\Comments\Data\Queries\CommentSelectorData;
use Nvl\Comments\Enums\CommentAudience;

/**
 * Defines the supported find latest target comment workflow.
 *
 * @api
 */
interface FindLatestTargetCommentContract
{
    /**
     * Return one audience-safe projection, or null when no visible row matches.
     */
    public function execute(
        Model $target,
        CommentActorData $actor,
        CommentSelectorData $selector,
        CommentAudience $audience = CommentAudience::Member,
    ): PublicCommentData|MemberCommentData|CommentManagementData|null;
}
