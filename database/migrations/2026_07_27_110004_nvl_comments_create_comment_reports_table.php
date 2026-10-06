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
     * Create per-actor moderation reports.
     */
    public function up(): void
    {
        $this->assertCanonicalManagedStorage();

        Schema::connection(PackageStorage::connection('comments'))->create(CommentsTables::get(CommentsTables::Reports), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('comment_id');
            $table->string('reporter_type', 100);
            $table->string('reporter_id', 255);
            $table->char('reporter_identity_hash', 64);
            $table->string('reason', 100);
            $table->text('details')->nullable();
            $table->string('status', 32)->default('open');
            $table->char('status_hash', 64);
            $table->string('reviewed_by_type', 100)->nullable();
            $table->string('reviewed_by', 255)->nullable();
            $table->text('resolution')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('comment_id')
                ->references('id')
                ->on(CommentsTables::get(CommentsTables::Comments))
                ->cascadeOnDelete();
            $table->unique(
                ['comment_id', 'reporter_identity_hash'],
                'comment_reports_reporter_unique',
            );
            $table->index(
                ['status_hash', 'created_at', 'id'],
                'comment_reports_status_idx',
            );
            $table->index(
                ['comment_id', 'status_hash', 'created_at', 'id'],
                'comment_reports_comment_idx',
            );
        });
    }

    /**
     * Drop reports.
     */
    public function down(): void
    {
        Schema::connection(PackageStorage::connection('comments'))->dropIfExists(CommentsTables::get(CommentsTables::Reports));
    }

    private function assertCanonicalManagedStorage(): void
    {
        PackageStorage::connection('comments');
    }
};
