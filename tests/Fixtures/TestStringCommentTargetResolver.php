<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Contracts\CommentTargetResolver;

/**
 * Resolves string-keyed targets for HTTP route contract tests.
 */
final class TestStringCommentTargetResolver implements CommentTargetResolver
{
    public function alias(): string
    {
        return 'string-article';
    }

    public function resolve(string $identifier): ?Model
    {
        return TestStringCommentTarget::query()->find($identifier);
    }
}
