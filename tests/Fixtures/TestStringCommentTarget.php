<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Contracts\HasComments;
use Nvl\Comments\Traits\InteractsWithComments;

/**
 * String-keyed target proving arbitrary scalar comment relationships.
 */
final class TestStringCommentTarget extends Model implements HasComments
{
    use InteractsWithComments;

    /** @var string */
    protected $table = 'comment_string_targets';

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = ['id', 'name'];
}
