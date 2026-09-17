<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Support\CommentsConfiguration;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(CommentsConfiguration::connection());
        $tables = [
            CommentsTables::Comments,
            CommentsTables::Reactions,
            CommentsTables::Revisions,
            CommentsTables::Reports,
            CommentsTables::MetadataValues,
            CommentsTables::Mentions,
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

        $schema->table(CommentsConfiguration::table(CommentsTables::Comments), static function (Blueprint $table): void {
            $table->dropUnique('comments_idempotency_key_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'comments_tenant_idempotency_unique');
            $table->index(['tenant_id', 'commentable_identity_hash', 'created_at', 'id'], 'comments_tenant_target_index');
        });
    }

    public function down(): void {}
};
