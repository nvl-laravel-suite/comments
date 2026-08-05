<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Contracts\HasComments;
use Nvl\Comments\Traits\InteractsWithComments;

/**
 * Target pinned to the isolated Comments test connection.
 */
final class TestConfiguredCommentTarget extends Model implements HasComments
{
    use InteractsWithComments;

    /** @var string */
    protected $connection = 'comments';

    /** @var string */
    protected $table = 'comment_configured_targets';

    /** @var list<string> */
    protected $fillable = ['name'];
}
