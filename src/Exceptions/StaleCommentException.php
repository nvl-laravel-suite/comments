<?php

declare(strict_types=1);

namespace Nvl\Comments\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

/**
 * @api

 * Raised when a comment changed after the caller read it.
 */
final class StaleCommentException extends CommentsException implements ShouldntReport
{
    /**
     * Create a stale-write exception for one comment.
     */
    public static function forComment(string $id): self
    {
        return new self("Comment [{$id}] changed after it was read.");
    }
}
