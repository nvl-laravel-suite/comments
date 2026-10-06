<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;

/**
 * Defines the supported attach comment media workflow.
 *
 * @api
 */
interface AttachCommentMediaContract
{
    /**
     * Attach authorized media when Comments and Media share one database connection.
     */
    public function execute(
        Comment|string $comment,
        Media|string $media,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): MediaAssociation;
}
