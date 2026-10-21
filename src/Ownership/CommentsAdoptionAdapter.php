<?php

declare(strict_types=1);

namespace Nvl\Comments\Ownership;

use Illuminate\Database\Schema\Blueprint;
use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\Services\OwnershipAdoptionStore;
use Nvl\Ownership\Services\OwnershipResourceBackfill;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use Nvl\Ownership\ValueObjects\OwnershipVerification;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/**
 * Adopts exact reviewed Comment records without guessing primary owners from associations.
 *
 * @internal
 */
final readonly class CommentsAdoptionAdapter implements OwnershipAdoptionAdapter
{
    public function __construct(
        private OwnershipResourceRegistry $resources,
        private OwnershipAdoptionStore $mappings,
        private OwnershipSchema $schema,
    ) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['comments.comments', 'comments.reactions', 'comments.revisions', 'comments.reports', 'comments.metadata', 'comments.mentions'];
    }

    /** Prepare additive partitions and replace only the package's known global receipt identity. */
    public function prepare(OwnershipAdoptionPlan $plan): void
    {
        foreach ($this->resources() as $key) {
            $model = new ($this->resources->get($key)->model);
            $schema = $model->getConnection()->getSchemaBuilder();
            if ($schema->hasColumn($model->getTable(), 'tenant_id')) {
                $schema->table($model->getTable(), static fn (Blueprint $table) => $table->uuid('tenant_id')->nullable()->change());
            }
            $this->schema->prepare($key);
        }
        $model = new ($this->resources->get('comments.comments')->model);
        $schema = $model->getConnection()->getSchemaBuilder();
        foreach ($schema->getIndexes($model->getTable()) as $index) {
            if ($index['unique'] && $index['columns'] === ['owner_scope_key', 'idempotency_key']) {
                return;
            }
            if ($index['unique'] && in_array($index['columns'], [['idempotency_key'], ['tenant_id', 'idempotency_key'], ['ownership_key', 'idempotency_key']], true)) {
                $schema->table($model->getTable(), static fn (Blueprint $table) => $table->dropUnique($index['name']));
            }
        }
        $schema->table($model->getTable(), static fn (Blueprint $table) => $table->unique(['owner_scope_key', 'idempotency_key'], 'comments_owner_idempotency_unique'));
    }

    /** Backfill bounded actual records from explicit immutable per-record mappings. */
    public function backfill(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
    {
        return (new OwnershipResourceBackfill($this->resources, $this->mappings))->run($plan, $this->resources(), $cursor, $limit, 'Comment');
    }

    public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification
    {
        foreach ($this->resources() as $key) {
            $this->schema->verify($key);
        }

        $model = new ($this->resources->get('comments.comments')->model);
        $connection = $model->getConnection();
        foreach ($connection->table($model->getTable())->cursor() as $row) {
            foreach (['parent_id', 'root_id'] as $field) {
                if ($row->{$field} === null) {
                    continue;
                }
                $parent = $connection->table($model->getTable())->where('id', $row->{$field})->first();
                if ($parent === null || $parent->owner_scope_key !== $row->owner_scope_key || $parent->commentable_type !== $row->commentable_type || $parent->commentable_id !== $row->commentable_id) {
                    throw new OwnershipConfigurationInvalid('Comment adoption requires consistent canonical target and hierarchy ownership.');
                }
            }
        }

        return new OwnershipVerification([]);
    }

    public function activate(OwnershipAdoptionPlan $plan): void
    {
        foreach ($this->resources() as $key) {
            $this->schema->constrain($key);
        }
    }
}
