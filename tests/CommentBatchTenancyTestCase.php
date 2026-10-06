<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Comments\Providers\CommentsServiceProvider;
use Nvl\Comments\Tests\Fixtures\CommentBatchTenancyDirectory;
use Nvl\Comments\Tests\Fixtures\CommentBatchTenancyFixtureServiceProvider;
use Nvl\Comments\Tests\Fixtures\CommentBatchTenancyMaintenanceMode;
use Nvl\Comments\Tests\Fixtures\CommentBatchTenancyPlatformAccess;
use Nvl\Comments\Tests\Fixtures\TestCommentMentionResource;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;

/** Boots actual tenant adoption and ownership for Comments' batch contract. */
abstract class CommentBatchTenancyTestCase extends Orchestra
{
    use DatabaseMigrations;

    /** Reset native identities before booting the active runtime. */
    protected function setUp(): void
    {
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
        parent::setUp();
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [DataServiceProvider::class, SupportServiceProvider::class, TenancyServiceProvider::class, CommentBatchTenancyFixtureServiceProvider::class, FilterableServiceProvider::class, CommentsServiceProvider::class];
    }

    /** Configure enabled tenancy before providers validate ownership. */
    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'cache.default' => 'array',
            'nvl-comments.attachments.enabled' => false,
            'nvl-comments.tenancy.target_types' => [TestCommentMentionResource::class => TestCommentMentionResource::class],
            'nvl-tenancy.enabled' => true,
            'nvl-tenancy.resources.comments' => 'tenant',
            'nvl-tenancy.directory.driver' => 'host',
            'nvl-tenancy.directory.adapter' => null,
            'nvl-tenancy.access.platform' => null,
        ]);
        $app->instance(MaintenanceMode::class, new CommentBatchTenancyMaintenanceMode);
        $app->instance(TenantDirectory::class, new CommentBatchTenancyDirectory);
        $app->instance(PlatformAccess::class, new CommentBatchTenancyPlatformAccess);
    }

    /** Load opt-in tenant installation storage after regular package migrations. */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        $provider = new ReflectionClass(TenancyServiceProvider::class);
        $this->loadMigrationsFrom(dirname($provider->getFileName()).'/../../database/migrations/tenancy');
    }
}
