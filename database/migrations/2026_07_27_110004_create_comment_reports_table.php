<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Comments\Definitions\Tables\CommentsTables;

return new class extends Migration
{
    /**
     * Create per-actor moderation reports.
     */
    public function up(): void
    {
        $this->assertCanonicalManagedStorage();

        Schema::create(CommentsTables::Reports, function (Blueprint $table): void {
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
                ->on(CommentsTables::Comments)
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
        Schema::dropIfExists(CommentsTables::Reports);
    }

    private function assertCanonicalManagedStorage(): void
    {
        if (config('comments.connection') !== null
            || config('comments.tables.comment_reports') !== CommentsTables::Reports
            || config('comments.tables.comments') !== CommentsTables::Comments) {
            throw new LogicException(
                'The package-managed Comments migrations only own canonical tables on the default connection.',
            );
        }
    }
};
