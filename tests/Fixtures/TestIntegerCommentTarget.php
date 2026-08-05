<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Contracts\HasComments;
use Nvl\Comments\Traits\InteractsWithComments;

/**
 * Integer-keyed target proving string-normalized comment relationships.
 */
final class TestIntegerCommentTarget extends Model implements HasComments
{
    use InteractsWithComments;

    /** @var string */
    protected $table = 'comment_integer_targets';

    /** @var list<string> */
    protected $fillable = ['name'];
}
