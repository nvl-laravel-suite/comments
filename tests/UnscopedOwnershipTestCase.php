<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests;

use Nvl\Comments\Models\Comment;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\ValueObjects\OwnershipOperation;

/** Keeps Comments unscoped beside a separately owned native host resource. */
abstract class UnscopedOwnershipTestCase extends OwnershipTestCase
{
    protected function commentClass(): string
    {
        return Comment::class;
    }

    protected function adoptOwnership(): void
    {
        $coordinator = app(OwnershipAdoptionCoordinator::class);
        $operation = new OwnershipOperation('adopt-host', 'operator', '1');
        $plan = $coordinator->prepare(['fixture'], [], $operation);
        $coordinator->backfill($plan, 100, $operation);
        $coordinator->activate($plan, $operation);
    }
}
