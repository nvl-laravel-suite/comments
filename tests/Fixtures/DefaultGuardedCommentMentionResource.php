<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Default-guarded Eloquent resource used to verify declarative mention registration.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $registration_number
 * @property string|null $secret
 */
final class DefaultGuardedCommentMentionResource extends Model
{
    use SoftDeletes;

    /** @var string */
    protected $table = 'comment_mention_test_resources';

    /** @var string */
    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'name',
        'registration_number',
    ];

    /** @var list<string> */
    protected $hidden = [
        'secret',
        'tenant_id',
    ];
}
