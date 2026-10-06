<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Contracts\CommentBatchQueryScope;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Exceptions\CommentBatchReadException;
use Nvl\Comments\Models\Comment;
use Nvl\Support\Owners\OwnerBatch;

/** Defers actionable unsupported-policy failure until a nonempty read is used. */
final readonly class UnsupportedCommentBatchQueryScope implements CommentBatchQueryScope
{
    /** Fail closed when active single-target policies cannot prove batch compatibility. */
    public function authorizeOwners(OwnerBatch $owners, CommentActorData $actor, CommentAudience $audience): void
    {
        throw new CommentBatchReadException('Bind an explicit CommentBatchQueryScope adapter for the active CommentAuthorization and CommentQueryScope policies.');
    }

    /**
     * Reject unsupported batch SQL authorization without reading storage.
     *
     * @param  Builder<Comment>  $query
     */
    public function scopeBatchComments(Builder $query, OwnerBatch $owners, CommentActorData $actor, CommentAudience $audience): void
    {
        $this->authorizeOwners($owners, $actor, $audience);
    }

    /**
     * Reject unsupported correlated host SQL authorization without reading storage.
     *
     * @param  Builder<Comment>  $query
     */
    public function scopeHostComments(Builder $query, Model $ownerPrototype, CommentActorData $actor, CommentAudience $audience): void
    {
        throw new CommentBatchReadException('Bind an explicit CommentBatchQueryScope adapter for correlated host comment counts.');
    }
}
