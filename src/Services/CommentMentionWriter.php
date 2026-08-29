<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Support\Str;
use Nvl\Comments\Data\Mutations\CommentDocumentData;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentMention;

/**
 * Synchronizes current normalized mention rows from canonical documents.
 */
final readonly class CommentMentionWriter
{
    /**
     * Create the current mention row writer.
     */
    public function __construct(private CommentDocumentNormalizer $documents) {}

    /**
     * Replace current mention rows inside the caller-owned transaction.
     */
    public function synchronize(Comment $comment, ?CommentDocumentData $document): void
    {
        CommentMention::query()->where('comment_id', $comment->id)->delete();

        if ($document === null) {
            return;
        }

        $timestamp = now();
        $rows = [];

        foreach ($this->documents->references($document) as $reference) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'comment_id' => $comment->id,
                'token_id' => $reference->tokenId,
                'resource_alias' => $reference->resourceAlias,
                'resource_id' => $reference->resourceId,
                'resource_identity_hash' => hash(
                    'sha256',
                    "comment-mention-resource\0{$reference->resourceAlias}\0{$reference->resourceId}",
                ),
                'label_snapshot' => $reference->labelSnapshot,
                'position' => $reference->position,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        if ($rows !== []) {
            CommentMention::query()->insert($rows);
        }
    }

    /**
     * Remove all current mention rows for one comment.
     */
    public function delete(Comment $comment): void
    {
        CommentMention::query()->where('comment_id', $comment->id)->delete();
    }
}
