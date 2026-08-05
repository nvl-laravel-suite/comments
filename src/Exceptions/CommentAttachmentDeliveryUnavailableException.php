<?php

declare(strict_types=1);

namespace Nvl\Comments\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Raised when attachment delivery cannot issue its required signed capability URLs.
 */
final class CommentAttachmentDeliveryUnavailableException extends CommentsException
{
    /**
     * Render the configuration failure without exposing route internals.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Comment attachment delivery is unavailable.',
            'code' => 'comment_attachment_delivery_unavailable',
        ], 503);
    }
}
