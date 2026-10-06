<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Str;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Contracts\TenantRunner;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Support\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\ValueObjects\PlatformOperation;

/** Installs the complete Comment graph through the real adoption coordinator. */
final readonly class CommentBatchTenancyScenario
{
    public const string A = '00000000-0000-4000-8000-00000000000a';

    public const string B = '00000000-0000-4000-8000-00000000000b';

    /** Adopt Comments plus its canonical owner fixture. */
    public static function install(): self
    {
        expect(config('nvl-tenancy.enabled'))->toBeTrue();
        app(TenantResourceRegistry::class)->get('test.comment-owners');

        $operation = new PlatformOperation('fixture.adoption', 'test', 'fixture');
        $coordinator = app(TenantAdoptionCoordinator::class);
        $plan = $coordinator->prepare(['resource-fixture-owners', 'comments'], [], $operation);
        $done = false;
        for ($batch = 0; $batch < 20 && ! $done; $batch++) {
            $done = $coordinator->backfill($plan, 100, $operation);
        }
        expect($done)->toBeTrue();
        expect($coordinator->verify($plan)->passed())->toBeTrue();
        $coordinator->activate($plan, $operation);
        app(MaintenanceMode::class)->deactivate();

        return new self;
    }

    /**
     * Run one callback under an active tenant.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(string $tenant, Closure $callback): mixed
    {
        return app(TenantRunner::class)->run(new TenantId($tenant), $callback);
    }

    /** Create one canonical registered owner. */
    public function owner(string $tenant): TestCommentMentionResource
    {
        return $this->run($tenant, static function (): TestCommentMentionResource {
            $owner = new TestCommentMentionResource(['id' => (string) Str::uuid(), 'name' => 'Owner', 'registration_number' => 'R']);
            $owner->forceFill(app(TenantBoundary::class)->attributes('test.comment-owners'));
            $owner->save();

            return $owner->refresh();
        });
    }
}
