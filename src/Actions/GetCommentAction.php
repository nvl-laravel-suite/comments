<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentReadService;

/**
 * Loads and authorizes one comment for display.
 */
final readonly class GetCommentAction
{
    public function __construct(private CommentReadService $reads) {}

    /**
     * Resolve one comment through its canonical target and trusted audience scope.
     */
    public function execute(
        Comment|string $comment,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment {
        return $this->reads->findById(
            $comment,
            $actor,
            $audience,
        );
    }
}
