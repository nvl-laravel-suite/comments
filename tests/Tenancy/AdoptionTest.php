<?php

declare(strict_types=1);

use Nvl\Comments\Tenancy\CommentsAdoptionAdapter;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;

it('registers the canonical comments graph adopter', function (): void {
    expect(app(TenantAdoptionRegistry::class)->all()['comments'] ?? null)->toBe(CommentsAdoptionAdapter::class);
});
