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
     * Create current normalized comment mention references.
     */
    public function up(): void
    {
        $this->assertCanonicalManagedStorage();

        Schema::connection(PackageStorage::connection('comments'))->create(CommentsTables::get(CommentsTables::Mentions), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('comment_id');
            $table->uuid('token_id');
            $table->string('resource_alias', 100);
            $table->string('resource_id', 255);
            $table->char('resource_identity_hash', 64);
            $table->string('label_snapshot', 255);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->foreign('comment_id')
                ->references('id')
                ->on(CommentsTables::get(CommentsTables::Comments))
                ->cascadeOnDelete();
            $table->unique(['comment_id', 'token_id'], 'comment_mentions_token_unique');
            $table->index(
                ['resource_alias', 'resource_identity_hash'],
                'comment_mentions_resource_idx',
            );
            $table->index(['comment_id', 'position'], 'comment_mentions_position_idx');
        });
    }

    /**
     * Drop current normalized comment mention references.
     */
    public function down(): void
    {
        Schema::connection(PackageStorage::connection('comments'))->dropIfExists(CommentsTables::get(CommentsTables::Mentions));
    }

    /**
     * Ensure vendor migrations own only canonical default-connection storage.
     */
    private function assertCanonicalManagedStorage(): void
    {
        PackageStorage::connection('comments');
    }
};
