<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Comments\Models\Comment;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Privileged moderation representation containing actor and report facts.
 */
#[TypeScript]
final class CommentManagementData extends Data
{
    use DataTransform;

    /**
     * @param  list<string>|Optional  $tags
     * @param  list<CommentMetadataProjectionData>|Optional  $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $rootId,
        public readonly ?string $parentId,
        public readonly int $depth,
        public readonly string|Optional $body,
        public readonly CommentFormat|Optional $format,
        public readonly string|Optional|null $locale,
        #[LiteralTypeScriptType('Array<string>')]
        public readonly array|Optional $tags,
        #[DataCollectionOf(CommentMetadataProjectionData::class)]
        public readonly array|Optional $metadata,
        public readonly string|Optional|null $actorType,
        public readonly string|Optional|null $actorId,
        public readonly CommentStatus $status,
        public readonly CommentVisibility $visibility,
        public readonly int $revision,
        public readonly bool $deleted,
        public readonly int $replyCount,
        public readonly int|Optional $reactionCount,
        public readonly int $reportCount,
        public readonly int $openReportCount,
        public readonly bool|Optional $pinned,
        public readonly bool|Optional $edited,
        public readonly ?string $moderationReason,
        public readonly string|Optional|null $moderatedByType,
        public readonly string|Optional|null $moderatedBy,
        public readonly ?string $moderatedAt,
        public readonly string|Optional|null $deletedByType,
        public readonly string|Optional|null $deletedBy,
        public readonly ?string $deletedAt,
        public readonly string|Optional|null $restoredByType,
        public readonly string|Optional|null $restoredBy,
        public readonly ?string $restoredAt,
        public readonly string|Optional|null $anonymizedByType,
        public readonly string|Optional|null $anonymizedBy,
        public readonly ?string $anonymizedAt,
        public readonly ?string $anonymizationReason,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    /**
     * Build a privileged comment projection with lifetime and actionable report counts.
     *
     * @param  list<CommentMetadataProjectionData>|Optional  $metadata
     */
    public static function fromModel(
        Comment $comment,
        int $replyCount,
        bool $includeActorIdentity = false,
        array|Optional $metadata = new Optional,
    ): self {
        $tombstone = $comment->trashed() || $comment->anonymized_at !== null;
        $omitted = Optional::create();

        return new self(
            id: $comment->id,
            rootId: $comment->root_id,
            parentId: $comment->parent_id,
            depth: $comment->depth,
            body: $tombstone ? $omitted : $comment->body,
            format: $tombstone ? $omitted : $comment->format,
            locale: $tombstone ? $omitted : $comment->locale,
            tags: $tombstone
                ? $omitted
                : (is_array($comment->tags) ? $comment->tags : []),
            metadata: $tombstone ? $omitted : $metadata,
            actorType: ! $tombstone && $includeActorIdentity
                ? $comment->actor_type
                : $omitted,
            actorId: ! $tombstone && $includeActorIdentity
                ? $comment->actor_id
                : $omitted,
            status: $comment->status,
            visibility: $comment->visibility,
            revision: $comment->revision,
            deleted: $comment->trashed(),
            replyCount: $replyCount,
            reactionCount: $tombstone ? $omitted : $comment->reaction_count,
            reportCount: $comment->report_count,
            openReportCount: $comment->open_report_count,
            pinned: $tombstone ? $omitted : $comment->is_pinned,
            edited: $tombstone ? $omitted : $comment->edited_at !== null,
            moderationReason: $comment->moderation_reason,
            moderatedByType: $includeActorIdentity
                ? $comment->moderated_by_type
                : $omitted,
            moderatedBy: $includeActorIdentity ? $comment->moderated_by : $omitted,
            moderatedAt: $comment->moderated_at?->format(DATE_ATOM),
            deletedByType: $includeActorIdentity
                ? $comment->deleted_by_type
                : $omitted,
            deletedBy: $includeActorIdentity ? $comment->deleted_by : $omitted,
            deletedAt: $comment->deleted_at?->format(DATE_ATOM),
            restoredByType: $includeActorIdentity
                ? $comment->restored_by_type
                : $omitted,
            restoredBy: $includeActorIdentity ? $comment->restored_by : $omitted,
            restoredAt: $comment->restored_at?->format(DATE_ATOM),
            anonymizedByType: $includeActorIdentity
                ? $comment->anonymized_by_type
                : $omitted,
            anonymizedBy: $includeActorIdentity
                ? $comment->anonymized_by
                : $omitted,
            anonymizedAt: $comment->anonymized_at?->format(DATE_ATOM),
            anonymizationReason: $comment->anonymization_reason,
            createdAt: $comment->created_at->format(DATE_ATOM),
            updatedAt: $comment->updated_at->format(DATE_ATOM),
        );
    }
}
