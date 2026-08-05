<?php

declare(strict_types=1);

namespace Nvl\Comments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Filterable\Definitions\FilterDefinition;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Definitions\SortDefinition;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Enums\FilterValueType;

/**
 * One actor's reviewable report against a comment.
 *
 * @property string $id
 * @property string $comment_id
 * @property string $reporter_type
 * @property string $reporter_id
 * @property string $reporter_identity_hash
 * @property string $reason
 * @property string|null $details
 * @property CommentReportStatus $status
 * @property string $status_hash
 * @property string|null $reviewed_by_type
 * @property string|null $reviewed_by
 * @property string|null $resolution
 * @property Carbon|null $reviewed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Comment $comment
 */
final class CommentReport extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'comment_id',
        'reporter_type',
        'reporter_id',
        'reason',
        'details',
        'status',
        'reviewed_by_type',
        'reviewed_by',
        'resolution',
        'reviewed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'open'];

    /** @var list<string> */
    protected $hidden = ['reporter_identity_hash', 'status_hash'];

    /**
     * Persist derived identity columns before consumer listeners can halt events.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        $this->setAttribute(
            'reporter_identity_hash',
            CommentIdentity::pair($this->reporter_type, $this->reporter_id),
        );
        $this->setAttribute(
            'status_hash',
            CommentIdentity::value('report-status', $this->status),
        );

        return parent::save($options);
    }

    /**
     * Return the configured reports table.
     */
    public function getTable(): string
    {
        return CommentsConfiguration::table('comment_reports');
    }

    /**
     * Return the configured Comments database connection.
     */
    public function getConnectionName(): ?string
    {
        return CommentsConfiguration::connection() ?? parent::getConnectionName();
    }

    /**
     * Return the soft-deleted-aware comment owning this report.
     *
     * @return BelongsTo<Comment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class)->withTrashed();
    }

    /**
     * Return the management allowlist for report filters and deterministic sorts.
     */
    public static function filterSchema(): FilterSchema
    {
        $equalsOrSet = [FilterOperator::Equals, FilterOperator::In];
        $dateOperators = [
            FilterOperator::Equals,
            FilterOperator::Before,
            FilterOperator::After,
            FilterOperator::Between,
        ];

        return new FilterSchema(
            filters: [
                new FilterDefinition(
                    alias: 'status',
                    column: 'status',
                    type: FilterValueType::Enum,
                    operators: $equalsOrSet,
                    enumValues: array_column(CommentReportStatus::cases(), 'value'),
                ),
                new FilterDefinition(
                    alias: 'reason',
                    column: 'reason',
                    operators: [
                        FilterOperator::Equals,
                        FilterOperator::Contains,
                    ],
                ),
                new FilterDefinition(
                    alias: 'created',
                    column: 'created_at',
                    type: FilterValueType::DateTime,
                    operators: $dateOperators,
                ),
                new FilterDefinition(
                    alias: 'reviewed',
                    column: 'reviewed_at',
                    type: FilterValueType::DateTime,
                    operators: [
                        ...$dateOperators,
                        FilterOperator::IsNull,
                        FilterOperator::IsNotNull,
                    ],
                    nullable: true,
                ),
            ],
            sorts: [
                new SortDefinition('created', 'created_at'),
                new SortDefinition('updated', 'updated_at'),
                new SortDefinition('reviewed', 'reviewed_at'),
                new SortDefinition('id', 'id'),
            ],
            defaultSorts: ['-created', '-id'],
            maximumFilters: 6,
            maximumSorts: 3,
            maximumValuesPerFilter: 50,
            maximumStringLength: 255,
            tieBreakerSort: 'id',
        );
    }

    /**
     * Return persisted report casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CommentReportStatus::class,
            'reviewed_at' => 'immutable_datetime',
        ];
    }
}
