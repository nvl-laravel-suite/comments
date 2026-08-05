<?php

declare(strict_types=1);

namespace Nvl\Comments\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentChangeOperation;

/**
 * Announces one versioned, durable, privacy-bounded comment mutation.
 */
final class CommentChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * Create a durable comment mutation event.
     */
    public function __construct(
        public readonly string $commentId,
        public readonly string $targetType,
        public readonly string $targetId,
        public readonly CommentChangeOperation $operation,
        public readonly int $revision,
        public readonly CommentActorData $actor,
        public readonly int $schemaVersion = 1,
    ) {}
}
