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
     * Create actor reactions.
     */
    public function up(): void
    {
        $this->assertCanonicalManagedStorage();

        Schema::connection(PackageStorage::connection('comments'))->create(CommentsTables::get(CommentsTables::Reactions), function (Blueprint $table): void {
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
                ->on(CommentsTables::get(CommentsTables::Comments))
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
        Schema::connection(PackageStorage::connection('comments'))->dropIfExists(CommentsTables::get(CommentsTables::Reactions));
    }

    private function assertCanonicalManagedStorage(): void
    {
        PackageStorage::connection('comments');
    }
};
