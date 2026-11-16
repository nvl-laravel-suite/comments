<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests;

use Nvl\Media\Providers\MediaServiceProvider;
use ReflectionClass;

/** Committed Comments and Media race fixtures on the dedicated PostgreSQL source and Redis locks. */
abstract class NativeCommentMediaConcurrencyTestCase extends NativeCommentsConcurrencyTestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), MediaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set([
            'filesystems.default' => 'local',
            'nvl-media.disk' => 'local',
            'nvl-media.queue.connection' => 'sync',
            'nvl-media.routes.assets_enabled' => false,
            'nvl-media.mutation_lock.wait_seconds' => 3,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $migrations = dirname((string) (new ReflectionClass(MediaServiceProvider::class))->getFileName(), 3).'/database/migrations';
        foreach (glob($migrations.'/*.php') ?: [] as $path) {
            (require $path)->up();
        }
    }
}
