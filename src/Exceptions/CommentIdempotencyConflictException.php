<?php

declare(strict_types=1);

namespace Nvl\Comments\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rejects reuse of a comment idempotency key for a different canonical request.
 */
final class CommentIdempotencyConflictException extends CommentsException implements ShouldntReport
{
    /**
     * Create a conflict without exposing the existing comment identity.
     */
    public static function forKey(): self
    {
        return new self('The idempotency key was already used for a different comment request.');
    }

    /**
     * Render the stable idempotency conflict response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'comment_idempotency_conflict',
        ], 409);
    }
}
