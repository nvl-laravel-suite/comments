<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Nvl\Comments\ValueObjects\CommentMentionContext;

/**
 * Applies an application-owned authorization scope to declarative mention resources.
 */
interface CommentMentionResourceAuthorization
{
    /**
     * Constrain one resource query before the package selects any live data.
     *
     * @param  Builder<*>  $query
     */
    public function scope(Builder $query, CommentMentionContext $context): void;
}
