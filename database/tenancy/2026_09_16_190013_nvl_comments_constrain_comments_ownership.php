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
        foreach ([
            CommentsTables::get(CommentsTables::Comments),
            CommentsTables::get(CommentsTables::Reactions),
            CommentsTables::get(CommentsTables::Revisions),
            CommentsTables::get(CommentsTables::Reports),
            CommentsTables::get(CommentsTables::MetadataValues),
            CommentsTables::get(CommentsTables::Mentions),
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
            CommentsTables::get(CommentsTables::Comments),
            CommentsTables::get(CommentsTables::Reactions),
            CommentsTables::get(CommentsTables::Revisions),
            CommentsTables::get(CommentsTables::Reports),
            CommentsTables::get(CommentsTables::MetadataValues),
            CommentsTables::get(CommentsTables::Mentions),
        ] as $key) {
            $schema->table(
                CommentsConfiguration::table($key),
                static fn (Blueprint $table) => $table->uuid('tenant_id')->nullable()->change(),
            );
        }
    }
};
