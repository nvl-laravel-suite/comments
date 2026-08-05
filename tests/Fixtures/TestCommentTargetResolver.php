<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Contracts\CommentTargetResolver;

/**
 * Resolves the isolation-test target alias.
 */
final class TestCommentTargetResolver implements CommentTargetResolver
{
    public function alias(): string
    {
        return 'article';
    }

    public function resolve(string $identifier): ?Model
    {
        return TestCommentTarget::query()->find($identifier);
    }
}
