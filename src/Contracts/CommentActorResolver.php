<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Http\Request;
use Nvl\Comments\Data\CommentActorData;

/**
 * Resolves a transport request to a stable comment actor identity.
 */
interface CommentActorResolver
{
    /**
     * Resolve the actor represented by one HTTP request.
     */
    public function fromRequest(Request $request): CommentActorData;
}
