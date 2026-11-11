<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Support\Str;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentMetadataValue;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/**
 * Synchronizes the hash-only registered metadata index.
 *
 * @internal
 */
final readonly class CommentMetadataIndexWriter
{
    /**
     * Create the metadata index writer.
     */
    public function __construct(
        private CommentMetadataRegistry $registry,
        private OwnershipBoundary $boundary,
    ) {}

    /**
     * Replace all queryable index rows for one comment inside its transaction.
     *
     * @param  array<string, mixed>|null  $metadata
     *
     * @throws InvalidCommentMutationException
     */
    public function synchronize(Comment $comment, ?array $metadata): void
    {
        $this->boundary->query(CommentMetadataValue::query(), 'comments.metadata')
            ->where('comment_id', $comment->id)->delete();
        $rows = $this->registry->indexRows($metadata);

        if ($rows === []) {
            return;
        }

        $timestamp = now();
        $records = array_map(
            fn (array $row): array => [
                'id' => (string) Str::uuid(),
                ...$this->boundary->attributes('comments.metadata'),
                'comment_id' => $comment->id,
                ...$row,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            $rows,
        );

        $this->boundary->query(CommentMetadataValue::query(), 'comments.metadata')->insert($records);
    }

    /**
     * Remove every queryable metadata index row for one comment.
     */
    public function delete(Comment $comment): void
    {
        $this->boundary->query(CommentMetadataValue::query(), 'comments.metadata')
            ->where('comment_id', $comment->id)->delete();
    }
}
