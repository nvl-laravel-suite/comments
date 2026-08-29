<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentMetadataValue;
use Nvl\Comments\Support\CommentsConfiguration;

/**
 * Synchronizes and queries the hash-only registered metadata index.
 */
final readonly class CommentMetadataIndexWriter
{
    /**
     * Create the metadata index writer.
     */
    public function __construct(private CommentMetadataRegistry $registry) {}

    /**
     * Replace all queryable index rows for one comment inside its transaction.
     *
     * @param  array<string, mixed>|null  $metadata
     *
     * @throws InvalidCommentMutationException
     */
    public function synchronize(Comment $comment, ?array $metadata): void
    {
        CommentMetadataValue::query()->where('comment_id', $comment->id)->delete();
        $rows = $this->registry->indexRows($metadata);

        if ($rows === []) {
            return;
        }

        $timestamp = now();
        $records = array_map(
            static fn (array $row): array => [
                'id' => (string) Str::uuid(),
                'comment_id' => $comment->id,
                ...$row,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            $rows,
        );

        CommentMetadataValue::query()->insert($records);
    }

    /**
     * Remove every queryable metadata index row for one comment.
     */
    public function delete(Comment $comment): void
    {
        CommentMetadataValue::query()->where('comment_id', $comment->id)->delete();
    }

    /**
     * Apply bounded hash-only equality criteria to a comment query.
     *
     * @param  Builder<Comment>  $query
     * @param  array<string, string|int|bool|null>  $criteria
     */
    public function apply(Builder $query, array $criteria): void
    {
        $table = CommentsConfiguration::table(CommentsTables::MetadataValues);
        $commentId = (new Comment)->qualifyColumn('id');

        foreach ($this->registry->selectorRows($criteria) as $index => $row) {
            $alias = "comment_metadata_selector_{$index}";
            $query->whereExists(
                static function (QueryBuilder $subquery) use (
                    $alias,
                    $commentId,
                    $row,
                    $table,
                ): void {
                    $subquery
                        ->selectRaw('1')
                        ->from("{$table} as {$alias}")
                        ->whereColumn("{$alias}.comment_id", $commentId)
                        ->where("{$alias}.schema_namespace", $row['schema_namespace'])
                        ->where("{$alias}.field_name", $row['field_name'])
                        ->where("{$alias}.value_type", $row['value_type'])
                        ->where("{$alias}.value_hash", $row['value_hash']);
                },
            );
        }
    }
}
