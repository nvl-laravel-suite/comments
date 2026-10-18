<?php

declare(strict_types=1);

namespace Nvl\Comments\Events;

use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Comments\Ownership\CommentsOwnership;
use Nvl\Support\Contracts\DomainEvent;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/**
 * Announces one versioned, durable, privacy-bounded comment mutation.
 *
 * @api
 */
final class CommentChanged implements DomainEvent, OwnershipQueuedJob
{
    private readonly OwnershipJobEnvelope $ownershipEnvelope;

    /** Return the scalar authority captured when this fact occurred. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->ownershipEnvelope;
    }

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
    ) {
        $this->ownershipEnvelope = CommentsOwnership::envelope();
    }

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
