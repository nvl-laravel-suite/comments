<?php

declare(strict_types=1);
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Tests\UnscopedOwnershipTestCase;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(UnscopedOwnershipTestCase::class);
it('requires admission before capturing an unscoped event beside an owned resource', function (): void {
    expect(fn () => new CommentChanged('c', 't', '1', CommentChangeOperation::Created, 1, CommentActorData::system()))->toThrow(OwnershipViolation::class);
});
