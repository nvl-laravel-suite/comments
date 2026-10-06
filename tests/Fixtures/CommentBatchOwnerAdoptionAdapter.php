<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;
use RuntimeException;

/** Owns the empty canonical Comment owner fixture schema. */
final readonly class CommentBatchOwnerAdoptionAdapter implements TenantAdoptionAdapter
{
    /** @return list<string> */
    public function resources(): array
    {
        return ['test.comment-owners', 'test.live-comment-owners'];
    }

    /** Create the owner table with direct tenant identity. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        $schema = $this->connection()->getSchemaBuilder();
        if (! $schema->hasTable((new TestCommentMentionResource)->getTable())) {
            $schema->create((new TestCommentMentionResource)->getTable(), static function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->uuid('tenant_id');
                $table->string('name');
                $table->string('registration_number');
                $table->softDeletes();
                $table->timestamps();
                $table->unique(['tenant_id', 'id'], 'test_comment_owners_tenant_id_unique');
            });
        }
        if (! $schema->hasTable((new BatchSoftDeletingCommentOwner)->getTable())) {
            $schema->create((new BatchSoftDeletingCommentOwner)->getTable(), static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('name');
                $table->softDeletes(BatchSoftDeletingCommentOwner::DELETED_AT);
                $table->timestamps();
                $table->unique(['tenant_id', 'id'], 'test_live_comment_owners_tenant_id_unique');
            });
        }
    }

    /** Require every fixture owner to retain its reviewed tenant identity. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        if ($this->connection()->table((new TestCommentMentionResource)->getTable())->whereNull('tenant_id')->exists()) {
            throw new RuntimeException('Comment owner fixture adoption found an unmapped owner.');
        }
        if ($this->connection()->table((new BatchSoftDeletingCommentOwner)->getTable())->whereNull('tenant_id')->exists()) {
            throw new RuntimeException('Live Comment owner fixture adoption found an unmapped owner.');
        }

        return new TenantBackfillResult(null, 0);
    }

    /** Verify owner columns and partition uniqueness. */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $schema = $this->connection()->getSchemaBuilder();
        $table = (new TestCommentMentionResource)->getTable();
        $errors = [];
        if (! $schema->hasColumns($table, ['id', 'tenant_id', 'name', 'created_at', 'updated_at'])) {
            $errors[] = 'test.comment-owners.columns';
        }
        if (! $schema->hasIndex($table, ['tenant_id', 'id'], 'unique')) {
            $errors[] = 'test.comment-owners.identity';
        }
        $liveTable = (new BatchSoftDeletingCommentOwner)->getTable();
        if (! $schema->hasColumns($liveTable, ['id', 'tenant_id', 'name', 'created_at', 'updated_at', BatchSoftDeletingCommentOwner::DELETED_AT])) {
            $errors[] = 'test.live-comment-owners.columns';
        }
        if (! $schema->hasIndex($liveTable, ['tenant_id', 'id'], 'unique')) {
            $errors[] = 'test.live-comment-owners.identity';
        }

        return new TenantVerification($errors);
    }

    /** The fixture schema is constrained during preparation. */
    public function activate(TenantAdoptionPlan $plan): void {}

    /** Resolve the fixture owner's effective connection. */
    private function connection(): Connection
    {
        return (new TestCommentMentionResource)->getConnection();
    }
}
