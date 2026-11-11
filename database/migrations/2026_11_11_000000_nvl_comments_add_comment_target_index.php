<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Support\Config\PackageStorage;

/**
 * Lets correlated host counts and `whereHas('comments')` find a target's comments by its
 * stored type and identifier instead of scanning the comments table once per host row.
 */
return new class extends Migration
{
    private const string INDEX = 'comments_target_pair_idx';

    /** Use the effective package connection for Laravel's migration transaction. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('comments');
    }

    public function up(): void
    {
        $schema = $this->schema();
        $table = CommentsTables::get(CommentsTables::Comments);

        if (! $schema->hasTable($table) || $schema->hasIndex($table, self::INDEX)) {
            return;
        }

        $schema->table($table, function (Blueprint $blueprint): void {
            $blueprint->index(['commentable_type', 'commentable_id'], self::INDEX);
        });
    }

    public function down(): void
    {
        $schema = $this->schema();
        $table = CommentsTables::get(CommentsTables::Comments);

        if (! $schema->hasTable($table) || ! $schema->hasIndex($table, self::INDEX)) {
            return;
        }

        $schema->table($table, function (Blueprint $blueprint): void {
            $blueprint->dropIndex(self::INDEX);
        });
    }

    private function schema(): Builder
    {
        return Schema::connection(PackageStorage::connection('comments'));
    }
};
