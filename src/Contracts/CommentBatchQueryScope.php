<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Support\Owners\OwnerBatch;

/**
 * Query-free admission and SQL authorization for batched and correlated reads.
 *
 * Implementations constrain nested queries; package ownership, tenant and deletion
 * predicates remain mandatory. Host predicates also authorize the requested audience.
 *
 * @api
 */
interface CommentBatchQueryScope
{
    /** Admit every canonical owner without issuing storage queries. */
    public function authorizeOwners(OwnerBatch $owners, CommentActorData $actor, CommentAudience $audience): void;

    /**
     * Constrain the visible comment set before counts and window ranks.
     *
     * @param  Builder<Comment>  $query
     */
    public function scopeBatchComments(Builder $query, OwnerBatch $owners, CommentActorData $actor, CommentAudience $audience): void;

    /**
     * Authorize and constrain correlated host reads without per-owner callbacks.
     *
     * @param  Builder<Comment>  $query
     */
    public function scopeHostComments(Builder $query, Model $ownerPrototype, CommentActorData $actor, CommentAudience $audience): void;
}
