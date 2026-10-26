<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Support\Enums\PublicActor;
use Nvl\Support\Enums\SystemActor;

/**
 * Consumer-owned policy boundary for all comment capabilities.
 *
 * @api
 */
interface CommentAuthorization
{
    /**
     * Determine whether an actor may perform a comment ability.
     *
     * Projection code may call this once per ability and comment. Implementations
     * must therefore use preloaded/request-cached state rather than per-call queries.
     *
     * @param  array<string, mixed>  $context
     */
    public function allows(
        CommentAbility $ability,
        Authenticatable|SystemActor|PublicActor $actor,
        ?Comment $comment = null,
        ?Model $target = null,
        CommentAudience $audience = CommentAudience::Public,
        array $context = [],
    ): bool;
}
