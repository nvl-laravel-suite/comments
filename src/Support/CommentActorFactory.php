<?php

declare(strict_types=1);

namespace Nvl\Comments\Support;

use Illuminate\Http\Request;
use Nvl\Comments\Contracts\CommentActorResolver;
use Nvl\Comments\Data\CommentActorData;

/**
 * Maps the current request user to a transport-neutral comment actor.
 */
final class CommentActorFactory implements CommentActorResolver
{
    /**
     * Resolve the actor represented by one HTTP request.
     */
    public function fromRequest(Request $request): CommentActorData
    {
        $user = $request->user();

        return $user === null
            ? CommentActorData::anonymous()
            : CommentActorData::fromAuthenticatable($user);
    }
}
