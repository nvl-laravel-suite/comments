<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Comments\Models\Comment;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Viewer-aware member projection with status and explicit mutation abilities.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class MemberCommentData extends Data
{
    use DataTransform;

    /**
     * @param  list<string>|Optional  $tags
     * @param  list<CommentMetadataProjectionData>|Optional  $metadata
     * @param  list<MemberCommentReactionSummaryData>|Optional  $reactions
     * @param  list<CommentMentionData>|Optional  $mentions
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
        public readonly int $revision,
        public readonly bool|Optional $deleted,
        public readonly int $replyCount,
        public readonly int|Optional $reactionCount,
        public readonly int|Optional $attachmentCount,
        public readonly bool|Optional $pinned,
        public readonly bool|Optional $edited,
        public readonly CommentAuthorData|Optional|null $author,
        #[DataCollectionOf(MemberCommentReactionSummaryData::class)]
        public readonly array|Optional $reactions,
        public readonly CommentStatus|Optional $status,
        public readonly CommentVisibility|Optional $visibility,
        public readonly bool|Optional $isAuthor,
        public readonly CommentAbilitiesData|Optional $abilities,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly CommentViewerDocumentData|Optional $document = new Optional,
        #[DataCollectionOf(CommentMentionData::class)]
        public readonly array|Optional $mentions = new Optional,
    ) {}

    /**
     * Build a member projection from one pre-authorized comment.
     *
     * @param  list<MemberCommentReactionSummaryData>  $reactions
     * @param  list<CommentMetadataProjectionData>|Optional  $metadata
     * @param  list<CommentMentionData>|Optional  $mentions
     */
    public static function fromModel(
        Comment $comment,
        ?CommentAuthorData $author,
        int $replyCount,
        array $reactions,
        bool $isAuthor,
        CommentAbilitiesData $abilities,
        int $attachmentCount = 0,
        array|Optional $metadata = new Optional,
        CommentViewerDocumentData|Optional $document = new Optional,
        array|Optional $mentions = new Optional,
    ): self {
        $tombstone = self::isTombstone($comment);
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
            revision: $comment->revision,
            deleted: $tombstone ? $omitted : false,
            replyCount: $replyCount,
            reactionCount: $tombstone ? $omitted : self::reactionCount($reactions),
            attachmentCount: $tombstone ? $omitted : $attachmentCount,
            pinned: $tombstone ? $omitted : $comment->is_pinned,
            edited: $tombstone ? $omitted : $comment->edited_at !== null,
            author: $tombstone ? $omitted : $author,
            reactions: $tombstone ? $omitted : $reactions,
            status: $tombstone ? $omitted : $comment->status,
            visibility: $tombstone ? $omitted : $comment->visibility,
            isAuthor: $tombstone ? $omitted : $isAuthor,
            abilities: $tombstone ? $omitted : $abilities,
            createdAt: $comment->created_at->format(DATE_ATOM),
            updatedAt: $comment->updated_at->format(DATE_ATOM),
            document: $tombstone ? $omitted : $document,
            mentions: $tombstone ? $omitted : $mentions,
        );
    }

    /**
     * @param  list<MemberCommentReactionSummaryData>  $reactions
     */
    private static function reactionCount(array $reactions): int
    {
        return array_sum(array_map(
            static fn (MemberCommentReactionSummaryData $reaction): int => $reaction->count,
            $reactions,
        ));
    }

    private static function isTombstone(Comment $comment): bool
    {
        return $comment->trashed()
            || $comment->getAttribute('anonymized_at') !== null;
    }
}
