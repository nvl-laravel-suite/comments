<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Exceptions\CommentTargetNotFoundException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentTargetIdentifier;

/**
 * Builds target-bound comment reads with trusted authorization scoping.
 */
final readonly class CommentReadService
{
    public function __construct(
        private CommentQueryScope $queryScope,
        private CommentAccessService $access,
        private CommentTargetLocator $targets,
    ) {}

    /**
     * Build a target-bound query before caller filters, sorts, and pagination.
     *
     * @return Builder<Comment>
     */
    public function query(
        Model $target,
        CommentActorData $actor,
        CommentAudience $audience,
        bool $withTrashed = true,
    ): Builder {
        return $this->queryFor(
            $target,
            $actor,
            $audience,
            CommentAbility::List,
            $withTrashed,
        );
    }

    /**
     * Build a target-bound query through the scope for one explicit operation.
     *
     * @return Builder<Comment>
     */
    public function queryFor(
        Model $target,
        CommentActorData $actor,
        CommentAudience $audience,
        CommentAbility $ability,
        bool $withTrashed = true,
    ): Builder {
        CommentTargetIdentifier::canonical($target);

        $this->access->authorize(
            $ability,
            $actor,
            target: $target,
            audience: $audience,
        );

        return $this->scopedQuery(
            $target,
            $actor,
            $audience,
            $ability,
            $withTrashed,
        );
    }

    /**
     * Resolve one comment through its canonical target and audience scope.
     */
    public function find(
        Model $target,
        Comment|string $comment,
        CommentActorData $actor,
        CommentAudience $audience,
        bool $withTrashed = true,
    ): Comment {
        $commentId = $comment instanceof Comment ? $comment->id : $comment;
        $resolved = $this->resolve(
            $target,
            $commentId,
            $actor,
            $audience,
            CommentAbility::View,
            $withTrashed,
        );

        $this->access->authorize(
            CommentAbility::View,
            $actor,
            $resolved,
            $target,
            $audience,
            asNotFound: $audience !== CommentAudience::Management,
        );

        return $resolved;
    }

    /**
     * Resolve and authorize one comment when the caller has only its identifier.
     */
    public function findById(
        Comment|string $comment,
        CommentActorData $actor,
        CommentAudience $audience,
        bool $withTrashed = true,
    ): Comment {
        $resolved = $this->resolveById(
            $comment,
            $actor,
            $audience,
            CommentAbility::View,
            $withTrashed,
        );
        $target = $this->targets->locate($resolved);
        $this->access->authorize(
            CommentAbility::View,
            $actor,
            $resolved,
            $target,
            $audience,
            asNotFound: $audience !== CommentAudience::Management,
        );

        return $resolved;
    }

    /**
     * Resolve one comment through its canonical target and trusted operation scope.
     */
    public function resolve(
        Model $target,
        Comment|string $comment,
        CommentActorData $actor,
        CommentAudience $audience,
        CommentAbility $ability,
        bool $withTrashed = true,
        bool $lockForUpdate = false,
    ): Comment {
        $commentId = $comment instanceof Comment ? $comment->id : $comment;
        $query = $this->scopedQuery(
            $target,
            $actor,
            $audience,
            $ability,
            $withTrashed,
        )->whereKey($commentId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /**
     * Bootstrap a stored target identity, then complete comment resolution through the scope.
     */
    public function resolveById(
        Comment|string $comment,
        CommentActorData $actor,
        CommentAudience $audience,
        CommentAbility $ability,
        bool $withTrashed = true,
        bool $lockForUpdate = false,
    ): Comment {
        $commentId = $comment instanceof Comment ? $comment->id : $comment;
        $unscopedComment = Comment::query()
            ->withTrashed()
            ->findOrFail($commentId);

        try {
            $target = $this->targets->locate($unscopedComment);
        } catch (CommentTargetNotFoundException $exception) {
            if ($audience === CommentAudience::Management) {
                throw $exception;
            }

            throw (new ModelNotFoundException)->setModel(Comment::class);
        }

        return $this->resolve(
            $target,
            $commentId,
            $actor,
            $audience,
            $ability,
            $withTrashed,
            $lockForUpdate,
        );
    }

    /**
     * Build the canonical target query and apply the consumer boundary before ID lookup.
     *
     * @return Builder<Comment>
     */
    private function scopedQuery(
        Model $target,
        CommentActorData $actor,
        CommentAudience $audience,
        CommentAbility $ability,
        bool $withTrashed,
    ): Builder {
        $identity = CommentTargetIdentifier::canonical($target);

        $query = Comment::query()
            ->when($withTrashed, static fn (Builder $query): Builder => $query->withTrashed())
            ->where(
                'commentable_identity_hash',
                CommentIdentity::pair($identity['type'], $identity['id']),
            );

        $this->queryScope->scopeComments(
            $query,
            $actor,
            $target,
            $audience,
            $ability,
        );

        return $query;
    }
}
