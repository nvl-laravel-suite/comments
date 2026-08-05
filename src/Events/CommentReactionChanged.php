<?php

declare(strict_types=1);

namespace Nvl\Comments\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nvl\Comments\Data\CommentActorData;

/**
 * Announces a durable reaction toggle.
 */
final class CommentReactionChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $commentId,
        public readonly string $type,
        public readonly bool $active,
        public readonly CommentActorData $actor,
        public readonly int $schemaVersion = 1,
    ) {}
}
