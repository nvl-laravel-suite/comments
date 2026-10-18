<?php

declare(strict_types=1);

namespace Nvl\Comments\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Ownership\CommentsOwnership;
use Nvl\Comments\Relations\TextColumnComparison;
use Nvl\Comments\Services\CommentModelRegistry;
use Nvl\Comments\Tenancy\CommentTenantParentResolver;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Support\Owners\OwnerIdentity;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;

/** Reloads finite owners through canonical host and tenant scopes before package SQL. */
final readonly class CommentBatchOwnerAdmission
{
    /** Retain the active ownership runtime and explicit host target allowlist. */
    public function __construct(private OwnershipBoundary $boundary, private TenantResourceRegistry $resources, private CommentTenantParentResolver $targets, private OwnershipResourceRegistry $ownershipResources) {}

    /** Canonically admit complete input with one scoped reload per concrete class. */
    public function admit(OwnerBatch $input): OwnerBatch
    {
        $groups = [];
        foreach ($input->identities() as $index => $identity) {
            $owner = $input->owners()[$index];
            $this->assertPrototype($owner);
            CommentTargetIdentifier::canonical($owner);
            $groups[$identity->model][] = $identity->id;
        }
        $loaded = [];
        foreach ($groups as $class => $ids) {
            $prototype = new $class;
            $query = $this->scope($prototype->newQuery()->withoutEagerLoads());
            $query->where(function (Builder $keys) use ($prototype, $ids): void {
                foreach ($ids as $id) {
                    $keys->orWhere(function (Builder $key) use ($prototype, $id): void {
                        if ($prototype->getKeyType() === 'int') {
                            $key->whereKey(CommentTargetIdentifier::lookupKey($prototype, $id));
                        } else {
                            self::exact($key, $prototype->getQualifiedKeyName(), $id);
                        }
                    });
                }
            });
            foreach ($query->get() as $owner) {
                $identity = OwnerIdentity::fromModel($owner);
                $loaded[$identity->type][$identity->id] = $owner;
            }
        }
        $canonical = [];
        foreach ($input->identities() as $identity) {
            $canonical[] = $loaded[$identity->type][$identity->id]
                ?? throw new TenantBoundaryViolation('A Comment batch owner is absent, deleted or outside the active host/tenant scope.');
        }

        return OwnerBatch::fromModels($canonical);
    }

    /** Reject declared/effective connection or table drift without resolving foreign storage. */
    public function assertPrototype(Model $owner): void
    {
        $prototype = new ($owner::class);
        $comments = CommentModelRegistry::newInstance();
        if (($prototype->getConnectionName() !== null && $owner->getConnectionName() !== $prototype->getConnectionName())
            || ($owner->getConnectionName() !== null && $owner->getConnectionName() !== $prototype->getConnection()->getName())
            || $prototype->getTable() !== $owner->getTable()
            || $prototype->getConnection() !== $comments->getConnection()
            || $owner->getConnection() !== $comments->getConnection()) {
            throw new TenantBoundaryViolation('Comment owners must retain their canonical table and package connection.');
        }
        if (CommentsOwnership::declared()) {
            $key = CommentsOwnership::resource($prototype);
            if (! in_array($key, $this->ownershipResources->get('comments.comments')->parents(), true)) {
                throw new OwnershipViolation('Comment targets require their explicit parent resource.');
            }
        } elseif (CommentsOwnership::active()) {
            $this->resources->forModel($prototype);
            if (($this->targets->types()[$prototype->getMorphClass()] ?? null) !== $prototype::class) {
                throw new TenantBoundaryViolation('Comment batch owners require an exact nvl-comments.tenancy.target_types registration.');
            }
        }
    }

    /**
     * Retain mandatory registered ownership even if callers remove host global scopes.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function scope(Builder $query): Builder
    {
        if (CommentsOwnership::active()) {
            return $this->boundary->query($query, CommentsOwnership::resource($query->getModel()));
        }

        return $query;
    }

    /**
     * Apply byte-exact textual identity equality with bound values.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     */
    public static function exact(Builder $query, string $column, string $value): void
    {
        $connection = $query->getModel()->getConnection();
        $wrapped = TextColumnComparison::text($connection->getQueryGrammar()->wrap($column), $connection->getDriverName());
        $expression = TextColumnComparison::value($wrapped, $connection->getDriverName());
        $query->whereRaw($expression, [$value, $value]);
    }
}
