<?php

declare(strict_types=1);

namespace Nvl\Comments\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Raised when validated transport data violates a comment-domain invariant.
 */
final class InvalidCommentMutationException extends InvalidArgumentException implements ShouldntReport
{
    /**
     * Render the invalid mutation through a stable JSON response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'invalid_comment_mutation',
        ], 422);
    }
}
