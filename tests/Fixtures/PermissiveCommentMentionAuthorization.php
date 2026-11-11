<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Nvl\Comments\Contracts\CommentMentionResourceAuthorization;
use Nvl\Comments\ValueObjects\CommentMentionContext;

/**
 * Admits every mention resource row, for tests about identifier handling only.
 */
final class PermissiveCommentMentionAuthorization implements CommentMentionResourceAuthorization
{
    /** @param  Builder<*>  $query */
    public function scope(Builder $query, CommentMentionContext $context): void {}
}
