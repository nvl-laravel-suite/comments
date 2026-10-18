<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Providers\CommentsServiceProvider;
use Nvl\Comments\Tests\Fixtures\OwnershipActor;
use Nvl\Comments\Tests\Fixtures\OwnershipComment;
use Nvl\Comments\Tests\Fixtures\OwnershipCommentTarget;
use Nvl\Comments\Tests\Fixtures\OwnershipCommentTargetAdapter;
use Nvl\Comments\Tests\Fixtures\OwnershipCompany;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Ownership\Contracts\CentralAccess;
use Nvl\Ownership\Contracts\OwnerAccess;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Providers\OwnershipServiceProvider;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase;

/** Exercises committed ownership and audit behavior without NVL Tenancy or Auth providers. */
abstract class OwnershipTestCase extends TestCase
{
    private array $originalMorphMap = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        Relation::morphMap($this->originalMorphMap, false);
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [LocaleServiceProvider::class, SupportServiceProvider::class, FilterableServiceProvider::class, TranslatableServiceProvider::class, DataServiceProvider::class, OwnershipServiceProvider::class, CommentsServiceProvider::class];
    }

    /** @return class-string<Comment> */
    protected function commentClass(): string
    {
        return OwnershipComment::class;
    }

    /** Configure owned package roots before immutable resource registration. */
    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'nvl-core.owners' => [OwnershipCompany::class, OwnershipActor::class],
            'nvl-ownership.enabled' => true, 'nvl-ownership.migrations.enabled' => false,
            'nvl-ownership.central_operations' => ['media-maintain'],
            'nvl-tenancy.enabled' => false,
            'nvl-comments.models.comment' => $this->commentClass(),
            'nvl-comments.ownership.target_resources' => ['fixture.comment-targets'],
            'nvl-comments.attachments.enabled' => false,
            'nvl-comments.moderation.new_status' => 'approved',
            'nvl-comments.mutation_lock.enabled' => false,
            'nvl-comments.migrations.enabled' => false,
            'nvl-media.deduplication_lock.store' => 'array',
            'nvl-media.routes.api_enabled' => false,
            'auth.providers.users.model' => OwnershipActor::class,
        ]);
        if (getenv('NVL_OWNERSHIP_TEST_DRIVER') === 'pgsql') {
            $database = getenv('NVL_OWNERSHIP_TEST_DATABASE');
            if (! is_string($database) || preg_match('/^nvl_own_test_[a-z0-9_]+$/D', $database) !== 1) {
                throw new \RuntimeException('Native integration tests require a dedicated nvl_own_test_ database.');
            }
            $app['config']->set('database.connections.sqlite', ['driver' => 'pgsql', 'url' => null, 'host' => '/tmp', 'port' => '5432', 'database' => $database, 'username' => get_current_user(), 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer']);
        }
    }

    /** Install real package storage and activate through the reviewed coordinator. */
    protected function setUp(): void
    {
        $this->originalMorphMap = Relation::morphMap();
        parent::setUp();
        $schema = DB::getSchemaBuilder();
        if (getenv('NVL_OWNERSHIP_TEST_DRIVER') === 'pgsql') {
            if (DB::connection()->getDatabaseName() !== getenv('NVL_OWNERSHIP_TEST_DATABASE')) {
                throw new \RuntimeException('The actual native source differs from the dedicated test database.');
            }
            $schema->dropAllTables();
        }
        foreach (['comments_owned_companies', 'comments_owned_actors'] as $table) {
            $schema->create($table, static fn (Blueprint $table) => $table->unsignedBigInteger('id')->primary());
        }
        $schema->table('comments_owned_actors', static function (Blueprint $table): void {
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
        });
        OwnershipCompany::query()->insert([['id' => 7], ['id' => 8]]);
        OwnershipActor::query()->create(['id' => 1, 'name' => 'Operator', 'email' => 'operator@example.test', 'password' => 'private hash']);
        $schema->create('comments_owned_subjects', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->foreign('company_id')->references('id')->on('comments_owned_companies');
        });
        $this->app->make(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('fixture.comment-targets', 'fixture', OwnershipCommentTarget::class, OwnershipCommentTarget::ownershipDefinition()));
        $this->app->make(OwnershipAdoptionRegistry::class)->register('fixture', OwnershipCommentTargetAdapter::class);
        foreach (glob(__DIR__.'/../database/migrations/*.php') ?: [] as $path) {
            (require $path)->up();
        }
        (require __DIR__.'/../../ownership/database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
        $this->app->bind(OwnerAccess::class, static fn () => new class implements OwnerAccess
        {
            public function assertCanEnter(Authenticatable $actor, Model $owner): void {}
        });
        $this->app->bind(CentralAccess::class, static fn () => new class implements CentralAccess
        {
            public function assertCanRun(string $operation, Authenticatable $actor): void {}
        });
        $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
        {
            public function authorize(OwnershipOperation $operation): void {}
        });
        $this->app->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            public function activate(array $payload): void {}

            public function deactivate(): void {}

            public function active(): bool
            {
                return true;
            }

            public function data(): array
            {
                return [];
            }
        });
        $this->adoptOwnership();
    }

    /** Activate this fixture graph after its source tables and host policies exist. */
    protected function adoptOwnership(): void
    {
        $operation = new OwnershipOperation('adopt-comments', 'operator', '1');
        $coordinator = $this->app->make(OwnershipAdoptionCoordinator::class);
        $plan = $coordinator->prepare(['comments', 'fixture'], $this->reviewedMappings(), $operation);
        $coordinator->backfill($plan, 100, $operation);
        $coordinator->activate($plan, $operation);
    }

    /** @return list<OwnershipMapping> */
    protected function reviewedMappings(): array
    {
        return [];
    }
}
