<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Comments\Definitions\Tables\CommentsTables;

return new class extends Migration
{
    /**
     * Create actor reactions.
     */
    public function up(): void
    {
        $this->assertCanonicalManagedStorage();

        Schema::create(CommentsTables::Reactions, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('comment_id');
            $table->string('actor_type', 100);
            $table->string('actor_id', 255);
            $table->char('actor_identity_hash', 64);
            $table->string('type', 64);
            $table->char('type_hash', 64);
            $table->timestamps();

            $table->foreign('comment_id')
                ->references('id')
                ->on(CommentsTables::Comments)
                ->cascadeOnDelete();
            $table->unique(
                ['comment_id', 'actor_identity_hash', 'type_hash'],
                'comment_reactions_actor_type_unique',
            );
            $table->index(['comment_id', 'type_hash'], 'comment_reactions_type_idx');
            $table->index('actor_identity_hash', 'comment_reactions_actor_idx');
        });
    }

    /**
     * Drop reactions.
     */
    public function down(): void
    {
        Schema::dropIfExists(CommentsTables::Reactions);
    }

    private function assertCanonicalManagedStorage(): void
    {
        if (config('comments.connection') !== null
            || config('comments.tables.comment_reactions') !== CommentsTables::Reactions
            || config('comments.tables.comments') !== CommentsTables::Comments) {
            throw new LogicException(
                'The package-managed Comments migrations only own canonical tables on the default connection.',
            );
        }
    }
};
