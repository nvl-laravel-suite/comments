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
        foreach ([
            CommentsTables::Comments,
            CommentsTables::Reactions,
            CommentsTables::Revisions,
            CommentsTables::Reports,
            CommentsTables::MetadataValues,
            CommentsTables::Mentions,
        ] as $key) {
            $schema->table(
                CommentsConfiguration::table($key),
                static fn (Blueprint $table) => $table->uuid('tenant_id')->nullable(false)->change(),
            );
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(CommentsConfiguration::connection());
        foreach ([
            CommentsTables::Comments,
            CommentsTables::Reactions,
            CommentsTables::Revisions,
            CommentsTables::Reports,
            CommentsTables::MetadataValues,
            CommentsTables::Mentions,
        ] as $key) {
            $schema->table(
                CommentsConfiguration::table($key),
                static fn (Blueprint $table) => $table->uuid('tenant_id')->nullable()->change(),
            );
        }
    }
};
