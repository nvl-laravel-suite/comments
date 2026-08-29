<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nvl\Comments\Providers\CommentsServiceProvider;
use Nvl\Comments\Tests\Fixtures\TestCommentTargetResolver;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots Comments and only its declared runtime dependencies.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            DataServiceProvider::class,
            FilterableServiceProvider::class,
            TranslatableServiceProvider::class,
            MediaServiceProvider::class,
            CommentsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'cache.default' => 'file',
            'filesystems.default' => 'local',
            'media.disk' => 'local',
            'media.queue.connection' => 'sync',
            'media.routes.assets_enabled' => false,
            'comments.targets' => ['article' => TestCommentTargetResolver::class],
            'comments.moderation.new_status' => 'approved',
            'comments.moderation.edited_status' => 'approved',
            'comments.mutation_lock.allow_local_store' => true,
        ]);
    }

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        Schema::create('comment_test_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('comment_mention_test_resources', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('tenant_id');
            $table->string('name');
            $table->string('registration_number');
            $table->string('secret')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
