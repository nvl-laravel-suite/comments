<?php

declare(strict_types=1);

namespace Nvl\Comments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentsConfiguration;

/**
 * One actor's idempotent reaction type on a comment.
 *
 * @property string $id
 * @property string $comment_id
 * @property string $actor_type
 * @property string $actor_id
 * @property string $actor_identity_hash
 * @property string $type
 * @property string $type_hash
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Comment $comment
 */
final class CommentReaction extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['comment_id', 'actor_type', 'actor_id', 'type'];

    /** @var list<string> */
    protected $hidden = ['actor_identity_hash', 'type_hash'];

    /**
     * Persist derived identity columns before consumer listeners can halt events.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        $this->setAttribute(
            'actor_identity_hash',
            CommentIdentity::pair($this->actor_type, $this->actor_id),
        );
        $this->setAttribute(
            'type_hash',
            CommentIdentity::value('reaction-type', $this->type),
        );

        return parent::save($options);
    }

    public function getTable(): string
    {
        return CommentsConfiguration::table('comment_reactions');
    }

    public function getConnectionName(): ?string
    {
        return CommentsConfiguration::connection() ?? parent::getConnectionName();
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class)->withTrashed();
    }
}
