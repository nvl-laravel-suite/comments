<?php

declare(strict_types=1);

namespace Nvl\Comments\Ownership;

use Illuminate\Database\Schema\Blueprint;
use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\Services\OwnershipAdoptionStore;
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
        $processed = 0;
        do {
            $result = $this->backfillResource($plan, $cursor, $limit - $processed);
            $processed += $result->processed;
            $cursor = $result->nextCursor;
        } while ($cursor !== null && $processed < $limit);

        return new OwnershipBackfillResult($cursor, $processed);
    }

    /** Consume at most one resource page without counting empty tables as progress. */
    private function backfillResource(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
    {
        $parts = $cursor === null ? ['0', ''] : explode(':', $cursor, 2);
        if (count($parts) !== 2 || preg_match('/^[0-9]+$/D', $parts[0]) !== 1) {
            throw new OwnershipConfigurationInvalid('Invalid Comment adoption cursor.');
        }
        $keys = $this->resources();
        $position = (int) $parts[0];
        $key = $keys[$position] ?? throw new OwnershipConfigurationInvalid('Invalid Comment adoption resource.');
        $definition = $this->resources->get($key);
        $model = new $definition->model;
        $connection = $model->getConnection();
        $rows = $connection->table($model->getTable())->when($parts[1] !== '', static fn ($query) => $query->where('id', '>', $parts[1]))->orderBy('id')->limit($limit)->get();
        $last = '';
        $connection->transaction(function () use ($rows, $key, $plan, $definition, $model, $connection, &$last): void {
            foreach ($rows as $row) {
                if (! is_string($row->id)) {
                    throw new OwnershipConfigurationInvalid('Comment adoption requires canonical UUID records.');
                }
                $mapping = $this->mappings->forRecord($plan, $key, $row->id);
                $owner = $mapping->owner;
                if (isset($row->tenant_id) && $mapping->legacyTenantId !== $row->tenant_id) {
                    throw new OwnershipConfigurationInvalid('Reviewed Comment mappings must preserve the original tenant identity.');
                }
                $nativeOwner = $definition->ownership->foreignKey === null ? null : ($row->{$definition->ownership->foreignKey} ?? null);
                if ($nativeOwner !== null && ((! is_int($nativeOwner) && ! is_string($nativeOwner)) || (string) $nativeOwner !== $owner?->key)) {
                    throw new OwnershipConfigurationInvalid('Comment adoption cannot relabel an existing native primary owner.');
                }
                $attributes = ['owner_scope_key' => $owner?->scopeKey() ?? 'central'];
                $ownership = $definition->ownership;
                if ($ownership->strategy === 'captured') {
                    $attributes['owner_reference'] = $owner === null ? null : json_encode($owner->toArray(), JSON_THROW_ON_ERROR);
                } elseif ($ownership->strategy === 'column') {
                    $attributes[$ownership->foreignKey ?? throw new OwnershipConfigurationInvalid] = $owner?->key;
                } elseif ($ownership->strategy === 'pivot' && $owner !== null) {
                    $connection->table($ownership->table ?? throw new OwnershipConfigurationInvalid)->insertOrIgnore([
                        $ownership->resourceKey ?? throw new OwnershipConfigurationInvalid => $row->id,
                        $ownership->ownerKey ?? throw new OwnershipConfigurationInvalid => $owner->key,
                        'owner_scope_key' => $owner->scopeKey(),
                    ]);
                }
                $connection->table($model->getTable())->where('id', $row->id)->update($attributes);
                $last = $row->id;
            }
        });
        $next = $rows->count() === $limit ? $position.':'.$last : ($position + 1 < count($keys) ? ($position + 1).':' : null);

        return new OwnershipBackfillResult($next, $rows->count());
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
