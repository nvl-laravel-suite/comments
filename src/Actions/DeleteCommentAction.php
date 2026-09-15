<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\DeleteCommentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentDeletionWriter;

/**
 * Soft-deletes a comment while preserving its reply thread and audit history.
 */
final readonly class DeleteCommentAction
{
    /**
     * Create the comment deletion action.
     */
    public function __construct(private CommentDeletionWriter $writer) {}

    /**
     * Soft-delete a comment when its revision and actor authorization are current.
     */
    public function execute(
        Comment|string $comment,
        DeleteCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): bool {
        return $this->writer->delete($comment, $data, $actor, $audience);
    }
}
