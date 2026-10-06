<?php

declare(strict_types=1);

use Nvl\Comments\Providers\CommentsServiceProvider;
use Nvl\Comments\Tenancy\CommentsAdoptionAdapter;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;

it('registers the canonical comments graph adopter', function (): void {
    app()->register(TenancyServiceProvider::class);
    app()->register(CommentsServiceProvider::class, true);

    expect(app(TenantAdoptionRegistry::class)->all()['comments'] ?? null)->toBe(CommentsAdoptionAdapter::class);
});
