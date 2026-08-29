<?php

declare(strict_types=1);

namespace Nvl\Comments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Support\CommentsConfiguration;

/**
 * Hash-only lookup row for one registered queryable comment metadata value.
 *
 * @property string $id
 * @property string $comment_id
 * @property string $schema_namespace
 * @property string $field_name
 * @property string $value_type
 * @property string $value_hash
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Comment $comment
 */
final class CommentMetadataValue extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'comment_id',
        'schema_namespace',
        'field_name',
        'value_type',
        'value_hash',
    ];

    /**
     * Return the configured package-owned metadata index table.
     */
    public function getTable(): string
    {
        return CommentsConfiguration::table(CommentsTables::MetadataValues);
    }

    /**
     * Return the configured Comments connection when present.
     */
    public function getConnectionName(): ?string
    {
        return CommentsConfiguration::connection() ?? parent::getConnectionName();
    }

    /**
     * Return the comment owning this hash-only lookup row.
     *
     * @return BelongsTo<Comment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }
}
