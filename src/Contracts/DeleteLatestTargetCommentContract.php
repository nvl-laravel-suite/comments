<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Queries\CommentSelectorData;
use Nvl\Comments\Enums\CommentAudience;

/**
 * Defines the supported delete latest target comment workflow.
 *
 * @api
 */
interface DeleteLatestTargetCommentContract
{
    /**
     * Delete the latest authorized active match and return false when none exists.
     */
    public function execute(
        Model $target,
        CommentSelectorData $selector,
        CommentActorData $actor,
        CommentAudience $audience,
    ): bool;
}
