<?php

declare(strict_types=1);

namespace Nvl\Comments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\Comments\Database\Factories\CommentReactionFactory;
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Services\CommentModelRegistry;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;

/**
 * One actor's idempotent reaction type on a comment.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $comment_id
 * @property string $actor_type
 * @property string $actor_id
 * @property string $actor_identity_hash
 * @property string $type
 * @property string $type_hash
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Comment $comment
 *
 * @api
 */
final class CommentReaction extends Model implements Ownable
{
    /** @use HasFactory<CommentReactionFactory> */
    use HasFactory;

    use HasOwnership { save as private saveOwned; }
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'comment_id', 'actor_type', 'actor_id', 'type'];

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

        return $this->saveOwned($options);
    }

    public function getTable(): string
    {
        return CommentsConfiguration::table(CommentsTables::get(CommentsTables::Reactions));
    }

    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('comments') ?? parent::getConnectionName());
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(CommentModelRegistry::modelClass(), 'comment_id')->withTrashed();
    }

    /**
     * Return the package's runtime fixture factory.
     *
     * @internal
     */
    protected static function newFactory(): CommentReactionFactory
    {
        return CommentReactionFactory::new();
    }
}
