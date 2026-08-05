<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Support\Collection;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentAttachmentData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentAttachmentDataFactory;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Media\Models\MediaAssociation;

/**
 * Lists authorized, deliverable attachment associations for one scoped comment.
 */
final readonly class ListCommentAttachmentsAction
{
    public function __construct(
        private CommentAccessService $access,
        private CommentAttachmentDataFactory $dataFactory,
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

        if (config('comments.attachments.enabled', true) !== true
            || $comment->trashed()
            || $comment->anonymized_at !== null) {
            return new Collection;
        }

        return $comment->attachmentAssociations()
            ->with(['media.imageVariations', 'media.translations'])
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->map(
                fn (MediaAssociation $association): ?CommentAttachmentData => $this->dataFactory
                    ->fromAssociation($association, $comment, $target, $actor, $audience),
            )
            ->filter(static fn (?CommentAttachmentData $attachment): bool => $attachment !== null)
            ->values();
    }
}
