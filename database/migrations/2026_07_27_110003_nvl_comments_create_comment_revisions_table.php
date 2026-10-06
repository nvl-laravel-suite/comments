<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Support\Config\PackageStorage;

return new class extends Migration
{
    /** Use the effective package connection for Laravel's migration transaction. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('comments');
    }

    /**
     * Create immutable edit history.
     */
    public function up(): void
    {
        $this->assertCanonicalManagedStorage();

        Schema::connection(PackageStorage::connection('comments'))->create(CommentsTables::get(CommentsTables::Revisions), function (Blueprint $table): void {
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
                ->on(CommentsTables::get(CommentsTables::Comments))
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
        Schema::connection(PackageStorage::connection('comments'))->dropIfExists(CommentsTables::get(CommentsTables::Revisions));
    }

    private function assertCanonicalManagedStorage(): void
    {
        PackageStorage::connection('comments');
    }
};
