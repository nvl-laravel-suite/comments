<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

final class NativeCommentActor extends User
{
    protected $table = 'native_comment_actors';

    protected $guarded = [];
}
