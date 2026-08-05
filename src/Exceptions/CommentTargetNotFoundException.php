<?php

declare(strict_types=1);

namespace Nvl\Comments\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
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

    /**
     * Render the missing target through a stable JSON response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'comment_target_not_found',
        ], 404);
    }
}
