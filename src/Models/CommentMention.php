<?php

declare(strict_types=1);

namespace Nvl\Comments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\Comments\Database\Factories\CommentMentionFactory;
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Services\CommentModelRegistry;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;

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
 *
 * @api
 *
 * @nvl-consumer-read id
 */
final class CommentMention extends Model implements Ownable
{
    /** @use HasFactory<CommentMentionFactory> */
    use HasFactory;

    use HasOwnership;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
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
        return CommentsConfiguration::table(CommentsTables::get(CommentsTables::Mentions));
    }

    /**
     * Return the configured Comments connection.
     */
    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('comments') ?? parent::getConnectionName());
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
        return $this->belongsTo(CommentModelRegistry::modelClass(), 'comment_id')->withTrashed();
    }

    /**
     * Return the package's runtime fixture factory.
     *
     * @internal
     */
    protected static function newFactory(): CommentMentionFactory
    {
        return CommentMentionFactory::new();
    }
}
