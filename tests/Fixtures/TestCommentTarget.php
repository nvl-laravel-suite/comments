<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Contracts\HasComments;
use Nvl\Comments\Traits\InteractsWithComments;

/**
 * UUID target proving the public trait and relationship.
 */
final class TestCommentTarget extends Model implements HasComments
{
    use HasUuids;
    use InteractsWithComments;

    /** @var string */
    protected $table = 'comment_test_targets';

    /** @var list<string> */
    protected $fillable = ['name'];
}
