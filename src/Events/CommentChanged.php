<?php

declare(strict_types=1);

namespace Nvl\Comments\Events;

use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Support\Contracts\DomainEvent;

/**
 * Announces one versioned, durable, privacy-bounded comment mutation.
 *
 * @api
 */
final class CommentChanged implements DomainEvent
{
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

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
