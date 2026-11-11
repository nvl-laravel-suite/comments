<?php

declare(strict_types=1);

namespace Nvl\Comments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\Comments\Database\Factories\CommentReportFactory;
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Comments\Services\CommentModelRegistry;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;

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
 *
 * @api
 */
final class CommentReport extends Model implements Ownable
{
    /** @use HasFactory<CommentReportFactory> */
    use HasFactory;

    use HasOwnership { save as private saveOwned; }
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

        return $this->saveOwned($options);
    }

    /**
     * Return the configured reports table.
     */
    public function getTable(): string
    {
        return CommentsConfiguration::table(CommentsTables::get(CommentsTables::Reports));
    }

    /**
     * Return the configured Comments database connection.
     */
    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('comments') ?? parent::getConnectionName());
    }

    /**
     * Return the soft-deleted-aware comment owning this report.
     *
     * @return BelongsTo<Comment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(CommentModelRegistry::modelClass(), 'comment_id')->withTrashed();
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

    /**
     * Return the package's runtime fixture factory.
     *
     * @internal
     */
    protected static function newFactory(): CommentReportFactory
    {
        return CommentReportFactory::new();
    }
}
