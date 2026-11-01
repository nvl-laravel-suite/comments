<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Comments\Providers\CommentsServiceProvider;
use Nvl\Support\Providers\LocaleServiceProvider;
use Orchestra\Testbench\TestCase;
use RuntimeException;

/** Committed native race fixtures on an explicitly dedicated PostgreSQL/MySQL/MariaDB source. */
abstract class NativeCommentsConcurrencyTestCase extends TestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [LocaleServiceProvider::class, CommentsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $driver = getenv('NVL_COMMENTS_CONCURRENCY_DRIVER');
        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            return;
        }
        $database = getenv('NVL_COMMENTS_CONCURRENCY_DATABASE');
        if (! is_string($database) || preg_match('/^nvl_own_test_(?:commentrace_[a-f0-9]{8}_(?:pgsql|mysql|mariadb)|comments_[a-f0-9]{8}_primary)$/D', $database) !== 1) {
            throw new RuntimeException('Comment races require an explicitly dedicated Comments race or package database.');
        }
        $app['config']->set([
            'database.default' => 'comments_concurrency',
            'database.connections.comments_concurrency' => [
                'driver' => $driver, 'url' => null,
                'host' => getenv('NVL_COMMENTS_CONCURRENCY_HOST') ?: '127.0.0.1',
                'port' => getenv('NVL_COMMENTS_CONCURRENCY_PORT') ?: ($driver === 'pgsql' ? '5432' : '13306'),
                'database' => $database,
                'username' => getenv('NVL_COMMENTS_CONCURRENCY_USERNAME') ?: ($driver === 'pgsql' ? get_current_user() : 'root'),
                'password' => getenv('NVL_COMMENTS_CONCURRENCY_PASSWORD') ?: '',
                'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer',
            ],
            'database.redis.client' => 'phpredis',
            'database.redis.comments_concurrency' => ['host' => '127.0.0.1', 'port' => getenv('NVL_COMMENTS_CONCURRENCY_REDIS_PORT') ?: '16379', 'database' => 0],
            'cache.default' => 'redis',
            'cache.stores.redis.connection' => 'comments_concurrency',
            'cache.stores.redis.lock_connection' => 'comments_concurrency',
            'cache.prefix' => 'nvl:commentrace:'.$database.':',
            'nvl-comments.connection' => 'comments_concurrency',
            'nvl-comments.migrations.enabled' => false,
            'nvl-comments.moderation.new_status' => 'approved',
            'nvl-comments.moderation.edited_status' => 'approved',
            'nvl-comments.idempotency.digest_key' => 'native-process-race-key',
            'nvl-comments.locks.mutation.store' => 'redis',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $driver = getenv('NVL_COMMENTS_CONCURRENCY_DRIVER');
        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Native comment races require an explicitly dedicated PostgreSQL/MySQL/MariaDB database and Redis.');
        }
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_exec')) {
            $this->markTestSkipped('Native comment races require pcntl fork/exec.');
        }
        $connection = DB::connection();
        if ($connection->getDriverName() !== $driver || $connection->getDatabaseName() !== getenv('NVL_COMMENTS_CONCURRENCY_DATABASE')) {
            throw new RuntimeException('The actual Comments race connection differs from its dedicated source.');
        }
        $schema = $connection->getSchemaBuilder();
        $schema->dropAllTables();
        foreach (glob(__DIR__.'/../database/migrations/*.php') ?: [] as $path) {
            (require $path)->up();
        }
        $schema->create('native_comment_actors', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        $schema->create('comment_test_targets', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });
    }
}
