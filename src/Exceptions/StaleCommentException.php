<?php

declare(strict_types=1);

namespace Nvl\Comments\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
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

    /**
     * Render stale writes as conflict responses.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'stale_comment',
        ], 409);
    }
}
