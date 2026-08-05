<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TABLE_NAME = 'comments';

    /**
     * Create polymorphic threaded comments.
     */
    public function up(): void
    {
        $this->assertCanonicalManagedStorage();

        Schema::create(self::TABLE_NAME, function (Blueprint $table): void {
            $table->uuid('id');
            $table->primary('id');
            $table->string('commentable_type', 100);
            $table->string('commentable_id', 255);
            $table->char('commentable_identity_hash', 64);
            $table->uuid('root_id')->nullable();
            $table->uuid('parent_id')->nullable();
            $table->unsignedSmallInteger('depth')->default(0);
            $table->string('actor_type', 100)->nullable();
            $table->string('actor_id', 255)->nullable();
            $table->char('actor_identity_hash', 64)->nullable();
            $table->uuid('idempotency_key')->nullable();
            $table->char('idempotency_hash', 64)->nullable();
            $table->text('body');
            $table->string('format', 32)->default('plain');
            $table->string('locale', 35)->nullable();
            $table->string('status', 32)->default('pending');
            $table->char('status_hash', 64);
            $table->string('visibility', 32)->default('public');
            $table->char('visibility_hash', 64);
            $table->json('tags')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->unsignedInteger('reply_count')->default(0);
            $table->unsignedInteger('reaction_count')->default(0);
            $table->unsignedInteger('report_count')->default(0);
            $table->unsignedInteger('open_report_count')->default(0);
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('edited_at')->nullable();
            $table->string('moderated_by_type', 100)->nullable();
            $table->string('moderated_by', 255)->nullable();
            $table->text('moderation_reason')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->string('deleted_by_type', 100)->nullable();
            $table->string('deleted_by', 255)->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->string('restored_by_type', 100)->nullable();
            $table->string('restored_by', 255)->nullable();
            $table->timestamp('anonymized_at')->nullable();
            $table->string('anonymized_by_type', 100)->nullable();
            $table->string('anonymized_by', 255)->nullable();
            $table->text('anonymization_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('parent_id')
                ->references('id')
                ->on(self::TABLE_NAME)
                ->cascadeOnDelete();
            $table->index(
                [
                    'commentable_identity_hash',
                    'status_hash',
                    'visibility_hash',
                    'is_pinned',
                    'created_at',
                    'id',
                ],
                'comments_target_visibility_idx',
            );
            $table->index(
                [
                    'commentable_identity_hash',
                    'root_id',
                    'status_hash',
                    'visibility_hash',
                    'is_pinned',
                    'created_at',
                    'id',
                ],
                'comments_thread_order_idx',
            );
            $table->index(
                ['parent_id', 'is_pinned', 'created_at', 'id'],
                'comments_parent_order_idx',
            );
            $table->index(['root_id', 'depth', 'created_at'], 'comments_root_depth_idx');
            $table->index(['actor_identity_hash', 'created_at'], 'comments_actor_idx');
            $table->index(
                [
                    'commentable_identity_hash',
                    'status_hash',
                    'open_report_count',
                    'report_count',
                    'created_at',
                    'id',
                ],
                'comments_moderation_idx',
            );
            $table->index(
                [
                    'commentable_identity_hash',
                    'anonymized_at',
                    'deleted_at',
                    'id',
                ],
                'comments_lifecycle_idx',
            );
            $table->unique('idempotency_key', 'comments_idempotency_key_unique');
        });
    }

    /**
     * Drop comments.
     */
    public function down(): void
    {
        Schema::dropIfExists(self::TABLE_NAME);
    }

    /**
     * Ensure the vendor migration owns one immutable and reversible target.
     */
    private function assertCanonicalManagedStorage(): void
    {
        $tables = config('comments.tables');
        $canonicalTables = [
            'comments' => self::TABLE_NAME,
            'comment_reactions' => 'comment_reactions',
            'comment_revisions' => 'comment_revisions',
            'comment_reports' => 'comment_reports',
        ];

        if (config('comments.connection') !== null || $tables !== $canonicalTables) {
            throw new LogicException(
                'The package-managed Comments migrations only own the canonical tables on the default connection; '.
                'disable comments.migrations.enabled and use application-owned migrations for custom storage.',
            );
        }
    }
};
