<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use Nvl\Ownership\ValueObjects\OwnershipVerification;

/** Activates empty host-owned subject storage through actual partition constraints. */
final readonly class OwnershipCommentTargetAdapter implements OwnershipAdoptionAdapter
{
    public function __construct(private OwnershipSchema $schema) {}

    public function resources(): array
    {
        return ['fixture.comment-targets'];
    }

    public function prepare(OwnershipAdoptionPlan $plan): void
    {
        $this->schema->prepare('fixture.comment-targets');
    }

    public function backfill(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
    {
        return new OwnershipBackfillResult(null, 0);
    }

    public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification
    {
        $this->schema->verify('fixture.comment-targets');

        return new OwnershipVerification([]);
    }

    public function activate(OwnershipAdoptionPlan $plan): void
    {
        $this->schema->constrain('fixture.comment-targets');
    }
}
