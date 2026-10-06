<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers only the test-owned canonical Comment owner resource and adapter. */
final class CommentBatchTenancyFixtureServiceProvider extends ServiceProvider
{
    /** Register fixture ownership before final configuration validation. */
    public function boot(TenantResourceRegistry $resources, TenantAdoptionRegistry $adoptions): void
    {
        $resources->register(new TenantResourceDefinition(
            'test.comment-owners',
            'test.comment-owners',
            TestCommentMentionResource::class,
        ));
        $adoptions->register('resource-fixture-owners', CommentBatchOwnerAdoptionAdapter::class);
    }
}
