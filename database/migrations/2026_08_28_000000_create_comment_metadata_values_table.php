<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Comments\Definitions\Tables\CommentsTables;

return new class extends Migration
{
    /**
     * Create the portable hash-only registered metadata lookup index.
     */
    public function up(): void
    {
        $this->assertCanonicalManagedStorage();

        Schema::create(CommentsTables::MetadataValues, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('comment_id');
            $table->string('schema_namespace', 100);
            $table->string('field_name', 64);
            $table->string('value_type', 16);
            $table->char('value_hash', 64);
            $table->timestamps();

            $table->foreign('comment_id')
                ->references('id')
                ->on(CommentsTables::Comments)
                ->cascadeOnDelete();
            $table->unique(
                ['comment_id', 'schema_namespace', 'field_name'],
                'comment_metadata_values_owner_unique',
            );
            $table->index(
                ['schema_namespace', 'field_name', 'value_hash'],
                'comment_metadata_values_lookup_idx',
            );
        });
    }

    /**
     * Drop the registered metadata lookup index.
     */
    public function down(): void
    {
        Schema::dropIfExists(CommentsTables::MetadataValues);
    }

    /**
     * Ensure vendor migrations own only canonical default-connection storage.
     */
    private function assertCanonicalManagedStorage(): void
    {
        if (config('comments.connection') !== null
            || CommentsTables::get(CommentsTables::Comments) !== CommentsTables::Comments
            || CommentsTables::get(CommentsTables::MetadataValues) !== CommentsTables::MetadataValues) {
            throw new LogicException(
                'The package-managed Comments migrations only own canonical tables on the default connection.',
            );
        }
    }
};
