<?php

declare(strict_types=1);

namespace Nvl\Comments\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Signals that a concurrent comment mutation still owns the serialization lock.
 */
final class CommentMutationBusyException extends CommentsException implements ShouldntReport
{
    /**
     * Render a retryable comment mutation conflict.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'comment_mutation_busy',
        ], 409, ['Retry-After' => '1']);
    }
}
