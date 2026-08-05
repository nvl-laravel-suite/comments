<?php

declare(strict_types=1);

namespace Nvl\Comments\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Comments\Relations\CommentTargetMorphTo;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Filterable\Definitions\FilterDefinition;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Definitions\SortDefinition;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Enums\FilterValueType;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Enums\MimeType;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Traits\InteractsWithMedia;

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
 * @property int $revision
 * @property int $reply_count
 * @property int $reaction_count
 * @property int $report_count
 * @property int $open_report_count
 * @property bool $is_pinned
 * @property Carbon|null $edited_at
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
 * @property-read Collection<int, MediaAssociation> $attachmentAssociations
 */
final class Comment extends Model implements HasMedia
{
    use HasUuids;
    use InteractsWithMedia;
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
        'revision',
        'reply_count',
        'reaction_count',
        'report_count',
        'open_report_count',
        'is_pinned',
        'edited_at',
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
    ];

    /**
     * Persist derived identity columns before any consumer model listener can halt events.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        $this->synchronizeIdentityFingerprints();

        return parent::save($options);
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

    public function getTable(): string
    {
        return CommentsConfiguration::table('comments');
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
            'depth' => 'integer',
            'format' => CommentFormat::class,
            'status' => CommentStatus::class,
            'visibility' => CommentVisibility::class,
            'tags' => 'array',
            'metadata' => 'array',
            'revision' => 'integer',
            'reply_count' => 'integer',
            'reaction_count' => 'integer',
            'report_count' => 'integer',
            'open_report_count' => 'integer',
            'is_pinned' => 'boolean',
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
            $query = new Builder(DB::connection()->query());
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
        return $this->belongsTo(self::class, 'parent_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function root(): BelongsTo
    {
        return $this->belongsTo(self::class, 'root_id')->withTrashed();
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderByDesc('is_pinned')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * @return HasMany<CommentReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(CommentReaction::class);
    }

    /**
     * @return HasMany<CommentRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(CommentRevision::class)->orderByDesc('revision');
    }

    /**
     * @return HasMany<CommentReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(CommentReport::class);
    }

    /**
     * Media associations belonging to the public comment attachment slot.
     *
     * @return MorphMany<MediaAssociation, $this>
     */
    public function attachmentAssociations(): MorphMany
    {
        return $this->mediaAssociations()
            ->where('collection', 'attachments')
            ->where('is_active', true);
    }

    /**
     * Comment attachments are private and isolated by default.
     */
    public function registerMediaSlots(): void
    {
        $maximum = CommentsConfiguration::positiveInteger(
            'comments.attachments.maximum_per_comment',
            5,
        );
        $maximumFileBytes = CommentsConfiguration::positiveInteger(
            'comments.attachments.maximum_file_bytes',
            10 * 1024 * 1024,
        );
        $this->addMediaSlot('attachments')
            ->privateExclusive()
            ->onlyKeepLatest($maximum)
            ->maxFileSize($maximumFileBytes)
            ->acceptsMimeTypes([
                ...MimeType::images(),
                ...MimeType::documents(),
            ]);
    }

    /**
     * Return the package's allowlisted filter and sort schema.
     */
    public static function filterSchema(): FilterSchema
    {
        return new FilterSchema(
            filters: [
                new FilterDefinition(
                    alias: 'status',
                    column: 'status',
                    type: FilterValueType::Enum,
                    operators: [FilterOperator::Equals, FilterOperator::In],
                    enumValues: array_column(CommentStatus::cases(), 'value'),
                ),
                new FilterDefinition(
                    alias: 'visibility',
                    column: 'visibility',
                    type: FilterValueType::Enum,
                    operators: [FilterOperator::Equals, FilterOperator::In],
                    enumValues: array_column(CommentVisibility::cases(), 'value'),
                ),
                new FilterDefinition(
                    alias: 'locale',
                    column: 'locale',
                    operators: [FilterOperator::Equals],
                    nullable: true,
                ),
                new FilterDefinition(
                    alias: 'root',
                    column: 'root_id',
                    operators: [FilterOperator::Equals, FilterOperator::IsNull],
                    nullable: true,
                ),
                new FilterDefinition(
                    alias: 'deleted',
                    column: 'deleted_at',
                    type: FilterValueType::DateTime,
                    operators: [
                        FilterOperator::IsNull,
                        FilterOperator::IsNotNull,
                    ],
                    nullable: true,
                ),
                new FilterDefinition(
                    alias: 'anonymized',
                    column: 'anonymized_at',
                    type: FilterValueType::DateTime,
                    operators: [
                        FilterOperator::IsNull,
                        FilterOperator::IsNotNull,
                    ],
                    nullable: true,
                ),
                new FilterDefinition(
                    alias: 'created',
                    column: 'created_at',
                    type: FilterValueType::DateTime,
                    operators: [
                        FilterOperator::Equals,
                        FilterOperator::Before,
                        FilterOperator::After,
                        FilterOperator::Between,
                    ],
                ),
                new FilterDefinition(
                    alias: 'updated',
                    column: 'updated_at',
                    type: FilterValueType::DateTime,
                    operators: [
                        FilterOperator::Equals,
                        FilterOperator::Before,
                        FilterOperator::After,
                        FilterOperator::Between,
                    ],
                ),
            ],
            sorts: [
                new SortDefinition('created', 'created_at'),
                new SortDefinition('updated', 'updated_at'),
                new SortDefinition('reactions', 'reaction_count'),
                new SortDefinition('pinned', 'is_pinned'),
                new SortDefinition('id', 'id'),
            ],
            defaultSorts: ['-pinned', '-created'],
            tieBreakerSort: 'id',
        );
    }

    /**
     * Return the privileged moderation filter and aggregate sort schema.
     */
    public static function managementFilterSchema(): FilterSchema
    {
        $common = self::filterSchema();

        return new FilterSchema(
            filters: [
                ...$common->filters,
                new FilterDefinition(
                    alias: 'reports',
                    column: 'report_count',
                    type: FilterValueType::Integer,
                    operators: [
                        FilterOperator::Equals,
                        FilterOperator::Gt,
                        FilterOperator::Gte,
                    ],
                ),
                new FilterDefinition(
                    alias: 'open_reports',
                    column: 'open_report_count',
                    type: FilterValueType::Integer,
                    operators: [
                        FilterOperator::Equals,
                        FilterOperator::Gt,
                        FilterOperator::Gte,
                    ],
                ),
            ],
            sorts: [
                ...$common->sorts,
                new SortDefinition('reports', 'report_count'),
                new SortDefinition('open_reports', 'open_report_count'),
                new SortDefinition('last_reported', 'last_reported_at'),
            ],
            defaultSorts: ['-open_reports', '-last_reported', '-created'],
            maximumFilters: $common->maximumFilters,
            maximumSorts: $common->maximumSorts,
            maximumValuesPerFilter: $common->maximumValuesPerFilter,
            maximumStringLength: $common->maximumStringLength,
            tieBreakerSort: $common->tieBreakerSort,
        );
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
}
