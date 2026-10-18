<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Native host target independent from NVL tenancy.
 * @property string $id
 * @property int $company_id
 * @property string $name
 * @property string $owner_scope_key
 */
class OwnershipCommentTarget extends Model implements Ownable
{
    use HasOwnership, HasUuids;

    protected $table = 'comments_owned_subjects';

    protected $guarded = [];

    public $timestamps = false;

    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(OwnershipCompany::class, 'company_id');
    }
}
