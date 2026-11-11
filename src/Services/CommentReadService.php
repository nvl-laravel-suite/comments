<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Exceptions\CommentTargetNotFoundException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentTargetIdentifier;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/**
 * Builds target-bound comment reads with trusted authorization scoping.
 *
 * @internal
 */
final readonly class CommentReadService
{
    public function __construct(
        private CommentQueryScope $queryScope,
        private CommentAccessService $access,
        private CommentTargetLocator $targets,
        private OwnershipBoundary $boundary,
    ) {}

    /**
     * Build a target-bound query before caller filters, sorts, and pagination.
     *
     * @return Builder<Comment>
     */
    public function query(
        Model $target,
        CommentActor $actor,
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

    /** Count only audience-visible replies after the target's List admission.
     *
     * @param  Collection<int, Comment>  $comments
     * @return array<string, int>
     */
    public function replyCounts(Collection $comments, Model $target, CommentActor $actor, CommentAudience $audience): array
    {
        if ($comments->isEmpty()) {
            return [];
        }
        $counts = $this->scopedQuery($target, $actor, $audience, CommentAbility::List, withTrashed: false)
            ->whereIn('parent_id', $comments->pluck('id')->all())
            ->select('parent_id')->selectRaw('COUNT(*) AS visible_count')
            ->groupBy('parent_id')->toBase()->pluck('visible_count', 'parent_id');
        $result = [];
        foreach ($counts as $id => $count) {
            if ((! is_int($count) && ! is_string($count)) || ! is_numeric($count)) {
                throw new \LogicException('The visible comment reply count is not numeric.');
            }
            $result[(string) $id] = (int) $count;
        }

        return $result;
    }

    /**
     * Build a target-bound query through the scope for one explicit operation.
     *
     * @return Builder<Comment>
     */
    public function queryFor(
        Model $target,
        CommentActor $actor,
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
     * Resolve and authorize one comment when the caller has only its identifier.
     */
    public function findById(
        Comment|string $comment,
        CommentActor $actor,
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
        CommentActor $actor,
        CommentAudience $audience,
        CommentAbility $ability,
        bool $withTrashed = true,
        bool $lockForUpdate = false,
    ): Comment {
        $commentId = $this->identifier($comment);
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
        CommentActor $actor,
        CommentAudience $audience,
        CommentAbility $ability,
        bool $withTrashed = true,
        bool $lockForUpdate = false,
    ): Comment {
        $commentId = $this->identifier($comment);
        $unscopedComment = $this->boundary->query(CommentModelRegistry::query(), 'comments.comments')
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

    /** Refuse a malformed identifier as not found before it reaches a UUID column. */
    private function identifier(Comment|string $comment): string
    {
        $identifier = $comment instanceof Comment ? $comment->id : $comment;
        if (! Str::isUuid($identifier)) {
            throw (new ModelNotFoundException)->setModel(Comment::class, [$identifier]);
        }

        return $identifier;
    }

    /**
     * Build the canonical target query and apply the consumer boundary before ID lookup.
     *
     * @return Builder<Comment>
     */
    private function scopedQuery(
        Model $target,
        CommentActor $actor,
        CommentAudience $audience,
        CommentAbility $ability,
        bool $withTrashed,
    ): Builder {
        $identity = CommentTargetIdentifier::canonical($target);

        $query = $this->boundary->query(CommentModelRegistry::query()->useWritePdo(), 'comments.comments')
            ->when($withTrashed, static fn (Builder $query): Builder => $query->withTrashed())
            ->where(
                'commentable_identity_hash',
                CommentIdentity::pair($identity['type'], $identity['id']),
            );

        $query->where(function (Builder $scope) use ($actor, $target, $audience, $ability): void {
            $this->queryScope->scopeComments($scope, $actor->principal, $target, $audience, $ability);
        });

        return $query;
    }
}
