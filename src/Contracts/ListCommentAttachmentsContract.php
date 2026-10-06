<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Support\Collection;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentAttachmentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;

/**
 * Defines the supported list comment attachments workflow.
 *
 * @api
 */
interface ListCommentAttachmentsContract
{
    /**
     * Return bounded attachment DTOs without exposing Media persistence internals.
     *
     * @return Collection<int, CommentAttachmentData>
     */
    public function execute(
        Comment|string $comment,
        CommentActorData $actor,
        CommentAudience $audience,
    ): Collection;
}
