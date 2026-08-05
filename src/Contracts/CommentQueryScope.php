<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;

/**
 * Applies trusted actor and audience constraints to comment read queries.
 *
 * Implementations only constrain the query. Ability denial belongs to
 * CommentAuthorization so scoped aggregates cannot reopen an unrelated
 * authorization gate after their owning operation was authorized.
 */
interface CommentQueryScope
{
    /**
     * Scope comments for the explicit operation before filtering, sorting, pagination,
     * aggregate counts, or final identifier resolution.
     *
     * @param  Builder<Comment>  $query
     */
    public function scopeComments(
        Builder $query,
        CommentActorData $actor,
        Model $target,
        CommentAudience $audience,
        CommentAbility $ability,
    ): void;
}
