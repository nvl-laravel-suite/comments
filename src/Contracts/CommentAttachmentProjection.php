<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentAttachmentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;

/** Supplies optional attachment projections without importing Media persistence types. */
interface CommentAttachmentProjection
{
    /**
     * Return privacy-safe attachment counts for an authorized comment batch.
     *
     * @param  Collection<int, Comment>  $comments
     * @return array<string, int>
     */
    public function counts(Collection $comments, Model $target, CommentActorData $actor, CommentAudience $audience): array;

    /**
     * Return deliverable attachment DTOs for an authorized comment.
     *
     * @return SupportCollection<int, CommentAttachmentData>
     */
    public function attachments(Comment $comment, Model $target, CommentActorData $actor, CommentAudience $audience): SupportCollection;
}
