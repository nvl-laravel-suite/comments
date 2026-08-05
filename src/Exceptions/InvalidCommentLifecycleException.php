<?php

declare(strict_types=1);

namespace Nvl\Comments\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rejects a comment lifecycle transition that violates durable state invariants.
 */
final class InvalidCommentLifecycleException extends CommentsException implements ShouldntReport
{
    /**
     * Render the invalid lifecycle transition through a stable response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'invalid_comment_lifecycle',
        ], 422);
    }
}
