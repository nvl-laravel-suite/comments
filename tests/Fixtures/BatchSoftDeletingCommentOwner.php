<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Nvl\Comments\Contracts\HasComments;
use Nvl\Comments\Traits\InteractsWithComments;

/**
 * Canonical tenant owner exposing the public count scope and a custom deletion column.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $name
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class BatchSoftDeletingCommentOwner extends Model implements HasComments
{
    use HasUuids;
    use InteractsWithComments;
    use SoftDeletes;

    public const string DELETED_AT = 'archived_at';

    /** @var string */
    protected $table = 'comment_batch_soft_deleting_owners';

    /** @var list<string> */
    protected $fillable = ['name'];
}
