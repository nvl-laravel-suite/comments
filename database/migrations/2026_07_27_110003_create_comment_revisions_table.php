<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TABLE_NAME = 'comment_revisions';

    /**
     * Create immutable edit history.
     */
    public function up(): void
    {
        $this->assertCanonicalManagedStorage();

        Schema::create(self::TABLE_NAME, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('comment_id');
            $table->unsignedBigInteger('revision');
            $table->text('body');
            $table->string('format', 32);
            $table->string('locale', 35)->nullable();
            $table->json('tags')->nullable();
            $table->json('metadata')->nullable();
            $table->string('edited_by_type', 100)->nullable();
            $table->string('edited_by', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('comment_id')
                ->references('id')
                ->on('comments')
                ->cascadeOnDelete();
            $table->unique(['comment_id', 'revision'], 'comment_revisions_number_unique');
            $table->index(['comment_id', 'created_at'], 'comment_revisions_created_idx');
        });
    }

    /**
     * Drop comment history.
     */
    public function down(): void
    {
        Schema::dropIfExists(self::TABLE_NAME);
    }

    private function assertCanonicalManagedStorage(): void
    {
        if (config('comments.connection') !== null
            || config('comments.tables.comment_revisions') !== self::TABLE_NAME
            || config('comments.tables.comments') !== 'comments') {
            throw new LogicException(
                'The package-managed Comments migrations only own canonical tables on the default connection.',
            );
        }
    }
};
