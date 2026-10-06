<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Support\Collection;
use Nvl\Comments\Contracts\CommentAttachmentProjection;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentAttachmentData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\CommentTargetLocator;

/**
 * Lists authorized, deliverable attachment associations for one scoped comment.
 *
 * @api
 */
final readonly class ListCommentAttachmentsAction
{
    public function __construct(
        private CommentAccessService $access,
        private CommentAttachmentProjection $attachments,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
    ) {}

    /**
     * Return bounded attachment DTOs without exposing Media persistence internals.
     *
     * @return Collection<int, CommentAttachmentData>
     */
    public function execute(
        Comment|string $comment,
        CommentActorData $actor,
        CommentAudience $audience,
    ): Collection {
        $comment = $this->reads->resolveById(
            $comment,
            $actor,
            $audience,
            CommentAbility::View,
        );
        $target = $this->targets->locate($comment);
        $this->access->authorize(
            CommentAbility::View,
            $actor,
            $comment,
            $target,
            $audience,
            asNotFound: $audience !== CommentAudience::Management,
        );

        return $this->attachments->attachments($comment, $target, $actor, $audience);
    }
}
