<?php

declare(strict_types=1);

namespace Nvl\Comments\Models;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;
use Nvl\Comments\Database\Factories\CommentFactory;
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Comments\Ownership\CommentGraphOwnership;
use Nvl\Comments\Relations\CommentTargetMorphTo;
use Nvl\Comments\Services\CommentModelRegistry;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;

/**
 * Polymorphic user-authored comment or reply.
 *
 * @property string $id
 * @property string $commentable_type
 * @property string $commentable_id
 * @property string $commentable_identity_hash
 * @property string|null $root_id
 * @property string|null $parent_id
 * @property int $depth
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $actor_identity_hash
 * @property string|null $idempotency_key
 * @property string|null $idempotency_hash
 * @property string $body
 * @property CommentFormat $format
 * @property string|null $locale
 * @property CommentStatus $status
 * @property string $status_hash
 * @property CommentVisibility $visibility
 * @property string $visibility_hash
 * @property list<string>|null $tags
 * @property array<string, mixed>|null $metadata
 * @property array<string, mixed>|null $document
 * @property int $revision
 * @property int $reply_count
 * @property int $reaction_count
 * @property int $report_count
 * @property int $open_report_count
 * @property bool $is_pinned
 * @property string|null $pinned_by_type
 * @property string|null $pinned_by
 * @property Carbon|null $pinned_at
 * @property string|null $approved_by_type
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $edited_at
 * @property string|null $edited_by_type
 * @property string|null $edited_by
 * @property string|null $moderated_by_type
 * @property string|null $moderated_by
 * @property string|null $moderation_reason
 * @property Carbon|null $moderated_at
 * @property string|null $deleted_by_type
 * @property string|null $deleted_by
 * @property Carbon|null $restored_at
 * @property string|null $restored_by_type
 * @property string|null $restored_by
 * @property Carbon|null $anonymized_at
 * @property string|null $anonymized_by_type
 * @property string|null $anonymized_by
 * @property string|null $anonymization_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Model|null $commentable
 * @property-read Comment|null $parent
 * @property-read Comment|null $root
 * @property-read Collection<int, Comment> $replies
 * @property-read Collection<int, CommentReaction> $reactions
 * @property-read Collection<int, CommentRevision> $revisions
 * @property-read Collection<int, CommentReport> $reports
 * @property-read Collection<int, CommentMetadataValue> $metadataValues
 * @property-read Collection<int, CommentMention> $mentions
 * @property-read Collection<int, MediaAssociation> $attachmentAssociations
 *
 * @method MorphMany<MediaAssociation, $this> attachmentAssociations()
 * @method MorphMany<MediaAssociation, $this> mediaAssociations()
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read format
 * @nvl-consumer-read locale
 * @nvl-consumer-read status
 * @nvl-consumer-read visibility
 * @nvl-consumer-read revision
 * @nvl-consumer-read reply_count
 * @nvl-consumer-read reaction_count
 * @nvl-consumer-read report_count
 * @nvl-consumer-read open_report_count
 * @nvl-consumer-read is_pinned
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 * @nvl-consumer-read deleted_at
 */
class Comment extends Model implements Ownable
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    use HasOwnership { save as private saveOwned; }
    use HasUuids;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'commentable_type',
        'commentable_id',
        'root_id',
        'parent_id',
        'depth',
        'actor_type',
        'actor_id',
        'idempotency_key',
        'idempotency_hash',
        'body',
        'format',
        'locale',
        'status',
        'visibility',
        'tags',
        'metadata',
        'document',
        'revision',
        'reply_count',
        'reaction_count',
        'report_count',
        'open_report_count',
        'is_pinned',
        'pinned_by_type',
        'pinned_by',
        'pinned_at',
        'approved_by_type',
        'approved_by',
        'approved_at',
        'edited_at',
        'edited_by_type',
        'edited_by',
        'moderated_by_type',
        'moderated_by',
        'moderation_reason',
        'moderated_at',
        'deleted_by_type',
        'deleted_by',
        'restored_at',
        'restored_by_type',
        'restored_by',
        'anonymized_at',
        'anonymized_by_type',
        'anonymized_by',
        'anonymization_reason',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'depth' => 0,
        'format' => 'plain',
        'status' => 'pending',
        'visibility' => 'public',
        'revision' => 1,
        'reply_count' => 0,
        'reaction_count' => 0,
        'report_count' => 0,
        'open_report_count' => 0,
        'is_pinned' => false,
    ];

    /** @var list<string> */
    protected $hidden = [
        'commentable_identity_hash',
        'actor_identity_hash',
        'status_hash',
        'visibility_hash',
        'idempotency_key',
        'idempotency_hash',
        'document',
        'pinned_by_type',
        'pinned_by',
        'approved_by_type',
        'approved_by',
        'edited_by_type',
        'edited_by',
    ];

    /**
     * Persist derived identity columns before any consumer model listener can halt events.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        $this->synchronizeIdentityFingerprints();

        return $this->getConnection()->transaction(function () use ($options): bool {
            Container::getInstance()->make(CommentGraphOwnership::class)->assertHierarchy($this);

            return $this->saveOwned($options);
        });
    }

    private function synchronizeIdentityFingerprints(): void
    {
        $commentableType = $this->getAttribute('commentable_type');
        $commentableId = $this->getAttribute('commentable_id');

        if (! is_string($commentableType) || ! is_string($commentableId)) {
            throw new LogicException(
                'Comments require string target type and identifier values.',
            );
        }

        $this->setAttribute(
            'commentable_identity_hash',
            CommentIdentity::pair(
                $commentableType,
                $commentableId,
            ),
        );

        $actorType = $this->getAttribute('actor_type');
        $actorId = $this->getAttribute('actor_id');
        $this->setAttribute(
            'actor_identity_hash',
            CommentIdentity::actor(
                is_string($actorType) ? $actorType : null,
                is_string($actorId) ? $actorId : null,
            ),
        );
        $status = $this->getAttribute('status');
        $visibility = $this->getAttribute('visibility');

        if (! $status instanceof CommentStatus || ! $visibility instanceof CommentVisibility) {
            throw new LogicException('Comments require valid status and visibility values.');
        }

        $this->setAttribute(
            'status_hash',
            CommentIdentity::value('comment-status', $status),
        );
        $this->setAttribute(
            'visibility_hash',
            CommentIdentity::value('comment-visibility', $visibility),
        );
    }

    /** Resolve Laravel bindings through the exact configured root.
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Comment
    {
        return $this->routeBindingQuery($value, $field)?->first();
    }

    /** Resolve explicit trashed bindings without losing canonical ownership.
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveSoftDeletableRouteBinding($value, $field = null): ?Comment
    {
        return $this->routeBindingQuery($value, $field)?->withTrashed()->first();
    }

    /**
     * Build the canonical binding query, or nothing when a UUID column is asked for a malformed identifier.
     *
     * @return Builder<Comment>|null
     */
    private function routeBindingQuery(mixed $value, ?string $field): ?Builder
    {
        $model = CommentModelRegistry::newInstance();
        $field ??= $model->getRouteKeyName();
        if (in_array($field, $model->uniqueIds(), true) && ! $model->isValidUniqueId($value)) {
            return null;
        }

        return $model->newQuery()->where($field, $value);
    }

    public function getTable(): string
    {
        return CommentsConfiguration::table(CommentsTables::get(CommentsTables::Comments));
    }

    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('comments') ?? parent::getConnectionName());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'format' => CommentFormat::class,
            'status' => CommentStatus::class,
            'visibility' => CommentVisibility::class,
            'tags' => 'array',
            'metadata' => 'array',
            'document' => 'array',
            'revision' => 'integer',
            'reply_count' => 'integer',
            'reaction_count' => 'integer',
            'report_count' => 'integer',
            'open_report_count' => 'integer',
            'is_pinned' => 'boolean',
            'pinned_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'edited_at' => 'immutable_datetime',
            'moderated_at' => 'immutable_datetime',
            'restored_at' => 'immutable_datetime',
            'anonymized_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function commentable(): MorphTo
    {
        $type = 'commentable_type';
        $identifier = 'commentable_id';
        $relation = 'commentable';
        $morphType = $this->getAttribute($type);

        if (! is_string($morphType) || $morphType === '') {
            $query = new Builder($this->getConnection()->query());
            $query->setModel(new Pivot);

            return new CommentTargetMorphTo(
                $query,
                $this,
                $identifier,
                null,
                $type,
                $relation,
            );
        }

        $modelClass = Model::getActualClassNameForMorph($morphType);

        if (! is_a($modelClass, Model::class, true)) {
            throw new LogicException(
                "Comment target morph type [{$morphType}] is not an Eloquent model.",
            );
        }

        $target = new $modelClass;

        return new CommentTargetMorphTo(
            $target->newQuery(),
            $this,
            $identifier,
            $target->getKeyName(),
            $type,
            $relation,
        );
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(CommentModelRegistry::modelClass(), 'parent_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function root(): BelongsTo
    {
        return $this->belongsTo(CommentModelRegistry::modelClass(), 'root_id')->withTrashed();
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(CommentModelRegistry::modelClass(), 'parent_id')
            ->orderByDesc('is_pinned')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * @return HasMany<CommentReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(CommentReaction::class, 'comment_id');
    }

    /**
     * @return HasMany<CommentRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(CommentRevision::class, 'comment_id')->orderByDesc('revision');
    }

    /**
     * @return HasMany<CommentReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(CommentReport::class, 'comment_id');
    }

    /**
     * Return hash-only query rows for registered metadata fields.
     *
     * @return HasMany<CommentMetadataValue, $this>
     */
    public function metadataValues(): HasMany
    {
        return $this->hasMany(CommentMetadataValue::class, 'comment_id');
    }

    /**
     * Return current normalized mention references in document order.
     *
     * @return HasMany<CommentMention, $this>
     */
    public function mentions(): HasMany
    {
        return $this->hasMany(CommentMention::class, 'comment_id')->orderBy('position');
    }

    /**
     * Publicly visible approved comments only.
     *
     * @param  Builder<static>  $query
     */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query
            ->where(
                'status_hash',
                CommentIdentity::value('comment-status', CommentStatus::Approved),
            )
            ->where(
                'visibility_hash',
                CommentIdentity::value('comment-visibility', CommentVisibility::Public),
            );
    }

    /**
     * Return the package's runtime fixture factory.
     *
     * @internal
     */
    protected static function newFactory(): CommentFactory
    {
        return CommentFactory::new();
    }
}
