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
 * Current normalized reference to one registered application resource.
 *
 * @property string $id
 * @property string $comment_id
 * @property string $token_id
 * @property string $resource_alias
 * @property string $resource_id
 * @property string $resource_identity_hash
 * @property string $label_snapshot
 * @property int $position
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Comment $comment
 */
final class CommentMention extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'comment_id',
        'token_id',
        'resource_alias',
        'resource_id',
        'resource_identity_hash',
        'label_snapshot',
        'position',
    ];

    /** @var list<string> */
    protected $hidden = [
        'resource_identity_hash',
    ];

    /**
     * Return the configured mention table.
     */
    public function getTable(): string
    {
        return CommentsConfiguration::table(CommentsTables::Mentions);
    }

    /**
     * Return the configured Comments connection.
     */
    public function getConnectionName(): ?string
    {
        return CommentsConfiguration::connection() ?? parent::getConnectionName();
    }

    /**
     * Return mention attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * Return the owning comment.
     *
     * @return BelongsTo<Comment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class)->withTrashed();
    }
}
