<?php

declare(strict_types=1);

namespace Nvl\Comments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Support\CommentsConfiguration;

/**
 * Immutable snapshot of comment content before an edit.
 *
 * @property string $id
 * @property string $comment_id
 * @property int $revision
 * @property string $body
 * @property CommentFormat $format
 * @property string|null $locale
 * @property list<string>|null $tags
 * @property array<string, mixed>|null $metadata
 * @property string|null $edited_by_type
 * @property string|null $edited_by
 * @property Carbon $created_at
 * @property-read Comment $comment
 */
final class CommentRevision extends Model
{
    use HasUuids;

    public const null UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'comment_id',
        'revision',
        'body',
        'format',
        'locale',
        'tags',
        'metadata',
        'edited_by_type',
        'edited_by',
    ];

    public function getTable(): string
    {
        return CommentsConfiguration::table('comment_revisions');
    }

    public function getConnectionName(): ?string
    {
        return CommentsConfiguration::connection() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'format' => CommentFormat::class,
            'tags' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class)->withTrashed();
    }
}
