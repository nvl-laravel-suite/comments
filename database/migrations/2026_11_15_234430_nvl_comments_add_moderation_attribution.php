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

    public function up(): void
    {
        Schema::connection($this->getConnection())->table(CommentsTables::get(CommentsTables::Comments), static function (Blueprint $table): void {
            foreach (['approved', 'pinned'] as $action) {
                $table->string($action.'_by_type', 100)->nullable();
                $table->string($action.'_by', 255)->nullable();
                $table->timestamp($action.'_at')->nullable();
            }
            $table->string('edited_by_type', 100)->nullable();
            $table->string('edited_by', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table(CommentsTables::get(CommentsTables::Comments), static function (Blueprint $table): void {
            $table->dropColumn(['approved_by_type', 'approved_by', 'approved_at', 'pinned_by_type', 'pinned_by', 'pinned_at', 'edited_by_type', 'edited_by']);
        });
    }
};
