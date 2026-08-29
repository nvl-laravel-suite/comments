<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Nvl\Comments\Contracts\CommentMentionResourceAuthorization;
use Nvl\Comments\ValueObjects\CommentMentionContext;

/**
 * Limits mention resources to the actor's tenant identifier.
 */
final class TestCommentMentionAuthorization implements CommentMentionResourceAuthorization
{
    /**
     * Apply the package-test tenant boundary before mention data is selected.
     *
     * @param  Builder<*>  $query
     */
    public function scope(Builder $query, CommentMentionContext $context): void
    {
        $query->getQuery()->where('tenant_id', $context->actor->id);
    }
}
