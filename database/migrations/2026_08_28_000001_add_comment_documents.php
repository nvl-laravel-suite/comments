<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Comments\Definitions\Tables\CommentsTables;

return new class extends Migration
{
    /**
     * Add nullable versioned rich-document snapshots to comments and revisions.
     */
    public function up(): void
    {
        $this->assertCanonicalManagedStorage();

        Schema::table(CommentsTables::Comments, function (Blueprint $table): void {
            $table->json('document')->nullable();
        });
        Schema::table(CommentsTables::Revisions, function (Blueprint $table): void {
            $table->json('document')->nullable();
        });
    }

    /**
     * Remove versioned rich-document snapshots.
     */
    public function down(): void
    {
        Schema::table(CommentsTables::Revisions, function (Blueprint $table): void {
            $table->dropColumn('document');
        });
        Schema::table(CommentsTables::Comments, function (Blueprint $table): void {
            $table->dropColumn('document');
        });
    }

    /**
     * Ensure vendor migrations alter only canonical default-connection storage.
     */
    private function assertCanonicalManagedStorage(): void
    {
        if (config('comments.connection') !== null
            || CommentsTables::get(CommentsTables::Comments) !== CommentsTables::Comments
            || CommentsTables::get(CommentsTables::Revisions) !== CommentsTables::Revisions) {
            throw new LogicException(
                'The package-managed Comments migrations only own canonical tables on the default connection.',
            );
        }
    }
};
