<?php

declare(strict_types=1);

namespace Nvl\Comments\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

/**
 * @api

 * Raised when an API-safe comment target alias or identifier cannot be resolved.
 */
final class CommentTargetNotFoundException extends CommentsException implements ShouldntReport
{
    /**
     * Create an exception for an unknown target alias.
     */
    public static function forAlias(string $alias): self
    {
        return new self("Comment target [{$alias}] is not registered.");
    }

    /**
     * Create an exception for an unknown target identifier.
     */
    public static function forIdentifier(string $alias, string $identifier): self
    {
        return new self("Comment target [{$alias}:{$identifier}] does not exist.");
    }
}
