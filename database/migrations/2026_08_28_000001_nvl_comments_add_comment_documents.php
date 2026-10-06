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
     * Add nullable versioned rich-document snapshots to comments and revisions.
     */
    public function up(): void
    {
        $this->assertCanonicalManagedStorage();

        Schema::connection(PackageStorage::connection('comments'))->table(CommentsTables::get(CommentsTables::Comments), function (Blueprint $table): void {
            $table->json('document')->nullable();
        });
        Schema::connection(PackageStorage::connection('comments'))->table(CommentsTables::get(CommentsTables::Revisions), function (Blueprint $table): void {
            $table->json('document')->nullable();
        });
    }

    /**
     * Remove versioned rich-document snapshots.
     */
    public function down(): void
    {
        Schema::connection(PackageStorage::connection('comments'))->table(CommentsTables::get(CommentsTables::Revisions), function (Blueprint $table): void {
            $table->dropColumn('document');
        });
        Schema::connection(PackageStorage::connection('comments'))->table(CommentsTables::get(CommentsTables::Comments), function (Blueprint $table): void {
            $table->dropColumn('document');
        });
    }

    /**
     * Ensure vendor migrations alter only canonical default-connection storage.
     */
    private function assertCanonicalManagedStorage(): void
    {
        PackageStorage::connection('comments');
    }
};
