<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Nvl\Comments\Contracts\CommentBatchQueryScope;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Exceptions\CommentBatchReadException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Relations\TextColumnComparison;
use Nvl\Comments\Support\CommentBatchOwnerAdmission;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

/** Composes mandatory ownership/deletion predicates around nested SQL authorization. */
final readonly class CommentBatchQueryBuilder
{
    /** Retain active package ownership and canonical host admission. */
    public function __construct(private OwnershipBoundary $boundary, private CommentBatchOwnerAdmission $owners) {}

    /**
     * Build the identical visible set used by aggregate counts and ranked latest rows.
     *
     * @return Builder<Comment>
     */
    public function batch(OwnerBatch $batch, CommentActorData $actor, CommentAudience $audience, CommentBatchQueryScope $policy): Builder
    {
        $query = $this->base();
        $query->where(function (Builder $pairs) use ($batch): void {
            foreach ($batch->identities() as $identity) {
                $pairs->orWhere(function (Builder $pair) use ($identity): void {
                    $pair->where('commentable_identity_hash', CommentIdentity::pair($identity->type, $identity->id));
                    CommentBatchOwnerAdmission::exact($pair, 'commentable_type', $identity->type);
                    CommentBatchOwnerAdmission::exact($pair, 'commentable_id', $identity->id);
                });
            }
        });
        $query->where(fn (Builder $nested) => $policy->scopeBatchComments($nested, $batch, $actor, $audience));

        return $query;
    }

    /** Rank the already authorized set and bound transferred rows before hydration. */
    public function latest(QueryBuilder $visible, int $limit): QueryBuilder
    {
        $ranked = (clone $visible)->select([
            'id', 'commentable_type', 'commentable_id', 'commentable_identity_hash',
            'body', 'format', 'anonymized_at', 'created_at', 'updated_at',
        ])->selectRaw('ROW_NUMBER() OVER (PARTITION BY commentable_identity_hash ORDER BY created_at DESC, id DESC) AS nvl_comment_rank');

        return $visible->newQuery()->fromSub($ranked, 'nvl_ranked_comments')
            ->where('nvl_comment_rank', '<=', $limit)->orderBy('commentable_identity_hash')->orderBy('nvl_comment_rank');
    }

    /**
     * Add an authorized correlated count without replacing selected host columns.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function withVisibleCount(Builder $query, CommentActorData $actor, CommentAudience $audience, CommentBatchQueryScope $policy): Builder
    {
        $owner = $query->getModel();
        $this->owners->assertPrototype($owner);
        if ($query->getConnection() !== $owner->getConnection()
            || $query->getQuery()->from !== $owner->getTable()
            || $query->getQuery()->unions !== null) {
            throw new TenantBoundaryViolation('The Comment host count query must retain its canonical owner table and connection.');
        }
        $this->groupCallerPredicates($query);
        $prototype = new ($owner::class);
        if (in_array(SoftDeletes::class, class_uses_recursive($prototype), true) && method_exists($prototype, 'getQualifiedDeletedAtColumn')) {
            $column = $prototype->getQualifiedDeletedAtColumn();
            if (! is_string($column) || $column === '') {
                throw new TenantBoundaryViolation('A canonical Comment owner requires a valid soft-delete column.');
            }
            $query->whereNull($column);
        }
        $query = $this->owners->scope($query);
        $comments = $this->base();
        CommentBatchOwnerAdmission::exact($comments, 'commentable_type', $owner->getMorphClass());
        $connection = $comments->getModel()->getConnection();
        $grammar = $connection->getQueryGrammar();
        $left = $grammar->wrap($comments->getModel()->qualifyColumn('commentable_id'));
        $right = TextColumnComparison::text($grammar->wrap($owner->getQualifiedKeyName()), $connection->getDriverName());
        $comparison = new TextColumnComparison($left, $right, $connection->getDriverName());
        $comments->whereRaw($comparison);
        $comments->where(fn (Builder $nested) => $policy->scopeHostComments($nested, $owner, $actor, $audience));
        if ($query->getQuery()->columns === null) {
            $query->select($owner->qualifyColumn('*'));
        }

        return $query->addSelect(['nvl_visible_comment_count' => $comments->selectRaw('COUNT(*)')])->withCasts(['nvl_visible_comment_count' => 'integer']);
    }

    /**
     * Keep caller OR clauses inside every subsequently added mandatory owner guard.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     */
    private function groupCallerPredicates(Builder $query): void
    {
        $base = $query->getQuery();
        if ($base->wheres === []) {
            return;
        }
        $nested = $base->forNestedWhere();
        $nested->wheres = $base->wheres;
        $nested->setBindings($base->getRawBindings()['where'], 'where');
        $base->wheres = [];
        $base->setBindings([], 'where');
        $base->addNestedWhereQuery($nested);
    }

    /**
     * Preserve package tenant ownership and nondeleted rows for every audience.
     *
     * @return Builder<Comment>
     */
    private function base(): Builder
    {
        $query = CommentModelRegistry::query()->withoutEagerLoads();
        if (! in_array($query->getModel()->getConnection()->getDriverName(), ['sqlite', 'pgsql', 'mysql', 'mariadb'], true)) {
            throw new CommentBatchReadException('Comment batch reads require SQLite, PostgreSQL, MySQL or MariaDB window-query support.');
        }

        return $this->boundary->query($query, 'comments.comments')->whereNull($query->getModel()->qualifyColumn('deleted_at'));
    }
}
