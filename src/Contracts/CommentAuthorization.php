<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;

/**
 * Consumer-owned policy boundary for all comment capabilities.
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
        CommentActorData $actor,
        ?Comment $comment = null,
        ?Model $target = null,
        CommentAudience $audience = CommentAudience::Public,
        array $context = [],
    ): bool;
}
