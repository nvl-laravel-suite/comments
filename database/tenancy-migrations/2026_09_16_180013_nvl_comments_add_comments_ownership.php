<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Support\Config\PackageStorage;

return new class extends Migration
{
    /** Use the effective package connection for Laravel's migration transaction. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('comments');
    }

    public function up(): void
    {
        $schema = Schema::connection(CommentsConfiguration::connection());
        $tables = [
            CommentsTables::get(CommentsTables::Comments),
            CommentsTables::get(CommentsTables::Reactions),
            CommentsTables::get(CommentsTables::Revisions),
            CommentsTables::get(CommentsTables::Reports),
            CommentsTables::get(CommentsTables::MetadataValues),
            CommentsTables::get(CommentsTables::Mentions),
        ];

        foreach ($tables as $table) {
            $tableName = CommentsConfiguration::table($table);
            if (! $schema->hasColumn($tableName, 'tenant_id')) {
                $schema->table($tableName, static function (Blueprint $blueprint): void {
                    $blueprint->uuid('tenant_id')->nullable();
                    $blueprint->index(['tenant_id', 'id']);
                });
            }
        }

        $schema->table(CommentsConfiguration::table(CommentsTables::get(CommentsTables::Comments)), static function (Blueprint $table): void {
            $table->dropUnique('comments_idempotency_key_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'comments_tenant_idempotency_unique');
            $table->index(['tenant_id', 'commentable_identity_hash', 'created_at', 'id'], 'comments_tenant_target_index');
        });
    }

    public function down(): void {}
};
