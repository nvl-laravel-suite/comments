<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Support\Str;
use Nvl\Comments\Data\CommentMentionChangeData;
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

    /**
     * Return bounded unique removal facts from the current normalized rows.
     *
     * @return list<CommentMentionChangeData>
     */
    public function removals(Comment $comment): array
    {
        $identities = [];
        $mentions = CommentMention::query()
            ->where('comment_id', $comment->id)
            ->orderBy('position')
            ->orderBy('token_id')
            ->get();

        foreach ($mentions as $mention) {
            $key = hash(
                'sha256',
                "comment-mention-event\0{$mention->resource_alias}\0{$mention->resource_id}",
            );
            $identities[$key] ??= new CommentMentionChangeData(
                resourceAlias: $mention->resource_alias,
                resourceId: $mention->resource_id,
                tokenId: $mention->token_id,
            );
        }

        ksort($identities);

        return array_values($identities);
    }

    /**
     * Diff two normalized documents by alias and opaque resource identity.
     *
     * @return array{added: list<CommentMentionChangeData>, removed: list<CommentMentionChangeData>}
     */
    public function changes(
        ?CommentDocumentData $before,
        ?CommentDocumentData $after,
    ): array {
        $previous = $this->identities($before);
        $current = $this->identities($after);

        return [
            'added' => array_values(array_diff_key($current, $previous)),
            'removed' => array_values(array_diff_key($previous, $current)),
        ];
    }

    /**
     * Index the first token for each unique mention resource identity.
     *
     * @return array<string, CommentMentionChangeData>
     */
    private function identities(?CommentDocumentData $document): array
    {
        if ($document === null) {
            return [];
        }

        $identities = [];

        foreach ($this->documents->references($document) as $reference) {
            $key = hash(
                'sha256',
                "comment-mention-event\0{$reference->resourceAlias}\0{$reference->resourceId}",
            );
            $identities[$key] ??= new CommentMentionChangeData(
                resourceAlias: $reference->resourceAlias,
                resourceId: $reference->resourceId,
                tokenId: $reference->tokenId,
            );
        }

        ksort($identities);

        return $identities;
    }
}
