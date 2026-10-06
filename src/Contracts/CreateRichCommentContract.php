<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\CreateRichCommentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;

/**
 * Defines the supported create rich comment workflow.
 *
 * @api
 */
interface CreateRichCommentContract
{
    /**
     * Create an authorized rich root comment or reply for a persisted target.
     */
    public function execute(
        Model $target,
        CreateRichCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment;
}
