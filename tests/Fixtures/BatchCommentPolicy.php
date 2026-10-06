<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentBatchQueryScope;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\ConfiguredCommentAuthorization;
use Nvl\Support\Owners\OwnerBatch;

/** Stateful SQL policy fixture exposing canonical admission and nested-OR behavior. */
final class BatchCommentPolicy implements CommentAuthorization, CommentBatchQueryScope, CommentQueryScope
{
    /** @var list<Model> */
    public array $admitted = [];

    /** Retain a deliberately broad SQL predicate when requested. */
    public function __construct(public readonly bool $broad = false) {}

    /** @param array<string, mixed> $context */
    public function allows(CommentAbility $ability, CommentActorData $actor, ?Comment $comment = null, ?Model $target = null, CommentAudience $audience = CommentAudience::Public, array $context = []): bool
    {
        return (new ConfiguredCommentAuthorization)->allows($ability, $actor, $comment, $target, $audience, $context);
    }

    /** @param Builder<Comment> $query */
    public function scopeComments(Builder $query, CommentActorData $actor, Model $target, CommentAudience $audience, CommentAbility $ability): void
    {
        (new ConfiguredCommentAuthorization)->scopeComments($query, $actor, $target, $audience, $ability);
    }

    /** Capture canonical owner attributes without storage queries. */
    public function authorizeOwners(OwnerBatch $owners, CommentActorData $actor, CommentAudience $audience): void
    {
        $this->admitted = $owners->owners();
        (new ConfiguredCommentAuthorization)->authorizeOwners($owners, $actor, $audience);
    }

    /** @param Builder<Comment> $query */
    public function scopeBatchComments(Builder $query, OwnerBatch $owners, CommentActorData $actor, CommentAudience $audience): void
    {
        if ($this->broad) {
            $query->whereRaw('1 = 0')->orWhereRaw('1 = 1');

            return;
        }
        (new ConfiguredCommentAuthorization)->scopeBatchComments($query, $owners, $actor, $audience);
    }

    /** @param Builder<Comment> $query */
    public function scopeHostComments(Builder $query, Model $ownerPrototype, CommentActorData $actor, CommentAudience $audience): void
    {
        if ($this->broad) {
            $query->whereRaw('1 = 0')->orWhereRaw('1 = 1');

            return;
        }
        (new ConfiguredCommentAuthorization)->scopeHostComments($query, $ownerPrototype, $actor, $audience);
    }
}
