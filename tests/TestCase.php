<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Nvl\Comments\Providers\CommentsServiceProvider;
use Nvl\Comments\Tests\Fixtures\TestCommentTargetResolver;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots Comments and only its declared runtime dependencies.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /** @var list<string>|null Native schemas belong to a provider composition. */
    private static ?array $schemaProviders = null;

    /** Rebuild when a standalone fixture changes the native migration closure. */
    protected function beforeRefreshingDatabase(): void
    {
        $providers = array_keys($this->app->getLoadedProviders());
        sort($providers);
        if (self::$schemaProviders !== $providers) {
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        }
        self::$schemaProviders = $providers;
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LocaleServiceProvider::class,
            ...(class_exists(TranslatableServiceProvider::class) ? [TranslatableServiceProvider::class] : []),
            CommentsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'cache.default' => 'file',
            'filesystems.default' => 'local',
            'nvl-media.disk' => 'local',
            'nvl-media.queue.connection' => 'sync',
            'nvl-media.routes.assets_enabled' => false,
            'nvl-translatable.locales' => ['en', 'bg'],
            'nvl-comments.targets' => ['article' => TestCommentTargetResolver::class],
            'nvl-comments.moderation.new_status' => 'approved',
            'nvl-comments.moderation.edited_status' => 'approved',
            'nvl-comments.mutation_lock.allow_local_store' => true,
        ]);
    }

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        Schema::create('native_comment_actors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('comment_test_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        Schema::create('comment_integer_targets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // A host key whose collation differs from the comments table's on every driver.
        Schema::create('comment_string_targets', function (Blueprint $table): void {
            $table->string('id', 64)->primary()->collation(Schema::getConnection()->getDriverName() === 'pgsql' ? 'C' : 'NOCASE');
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
