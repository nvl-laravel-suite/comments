<?php

declare(strict_types=1);

namespace Nvl\Comments\Integrations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use Nvl\Comments\Contracts\CommentAttachmentProjection;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentAttachmentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Ownership\CommentsOwnership;
use Nvl\Comments\Services\CommentAttachmentDataFactory;
use Nvl\Comments\Services\CommentModelRegistry;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/** Projects deliverable Media associations through both packages' authorization boundaries. */
final readonly class MediaCommentAttachmentProjection implements CommentAttachmentProjection
{
    /** Retain the existing privacy-safe factory and tenant boundary. */
    public function __construct(private CommentAttachmentDataFactory $factory, private OwnershipBoundary $boundary) {}

    /**
     * Count deliverable attachments without introducing per-comment queries.
     *
     * @param  Collection<int, Comment>  $comments
     * @return array<string, int>
     */
    public function counts(Collection $comments, Model $target, CommentActorData $actor, CommentAudience $audience): array
    {
        CommentsOwnership::assertAttachmentSupport();
        $active = [];
        foreach ($comments as $comment) {
            if (! $comment->trashed() && $comment->anonymized_at === null) {
                $active[$comment->id] = $comment;
            }
        }
        if ($active === []) {
            return [];
        }
        $associations = $this->boundary->query(MediaAssociation::query(), 'media.associations')
            ->where('associable_type', (CommentModelRegistry::newInstance())->getMorphClass())
            ->whereIn('associable_id', array_keys($active))
            ->where('collection', 'attachments')->where('is_active', true)
            ->with(['media.imageVariations', 'media.translations'])
            ->orderBy('order')->orderBy('id')->get();
        $counts = [];
        foreach ($associations as $association) {
            $comment = $active[$association->associable_id] ?? null;
            if ($comment instanceof Comment && $this->factory->fromAssociation($association, $comment, $target, $actor, $audience) !== null) {
                $counts[$comment->id] = ($counts[$comment->id] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Return deliverable attachment DTOs for one active authorized comment.
     *
     * @return SupportCollection<int, CommentAttachmentData>
     */
    public function attachments(Comment $comment, Model $target, CommentActorData $actor, CommentAudience $audience): SupportCollection
    {
        CommentsOwnership::assertAttachmentSupport();
        if ($comment->trashed() || $comment->anonymized_at !== null) {
            return new SupportCollection;
        }

        return $this->boundary->query(MediaAssociation::query(), 'media.associations')
            ->where('associable_type', $comment->getMorphClass())->where('associable_id', $comment->id)
            ->where('collection', 'attachments')->where('is_active', true)
            ->with(['media.imageVariations', 'media.translations'])
            ->orderBy('order')->orderBy('id')->get()
            ->map(fn (MediaAssociation $association): ?CommentAttachmentData => $this->factory->fromAssociation($association, $comment, $target, $actor, $audience))
            ->filter(static fn (?CommentAttachmentData $attachment): bool => $attachment !== null)->values();
    }
}
