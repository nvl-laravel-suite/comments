<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Comments\Definitions\Tables\CommentsTables;

return new class extends Migration
{
    /**
     * Create current normalized comment mention references.
     */
    public function up(): void
    {
        $this->assertCanonicalManagedStorage();

        Schema::create(CommentsTables::Mentions, function (Blueprint $table): void {
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
                ->on(CommentsTables::Comments)
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
        Schema::dropIfExists(CommentsTables::Mentions);
    }

    /**
     * Ensure vendor migrations own only canonical default-connection storage.
     */
    private function assertCanonicalManagedStorage(): void
    {
        if (config('comments.connection') !== null
            || CommentsTables::get(CommentsTables::Comments) !== CommentsTables::Comments
            || CommentsTables::get(CommentsTables::Mentions) !== CommentsTables::Mentions) {
            throw new LogicException(
                'The package-managed Comments migrations only own canonical tables on the default connection.',
            );
        }
    }
};
