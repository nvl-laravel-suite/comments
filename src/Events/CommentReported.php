<?php

declare(strict_types=1);

namespace Nvl\Comments\Events;

use Nvl\Comments\Ownership\CommentsOwnership;
use Nvl\Comments\Services\CommentActor;
use Nvl\Support\Contracts\DomainEvent;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/**
 * Announces a durable report for moderation consumers.
 *
 * @api
 */
final class CommentReported implements DomainEvent, OwnershipQueuedJob
{
    /** @var array{type: ?string, id: ?string, system: bool} */
    public readonly array $actor;

    private readonly OwnershipJobEnvelope $ownershipEnvelope;

    /** Return the scalar authority captured when this fact occurred. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->ownershipEnvelope;
    }

    public function __construct(
        public readonly string $commentId,
        public readonly string $reportId,
        CommentActor $actor,
        public readonly int $schemaVersion = 1,
    ) {
        $this->actor = ['type' => $actor->type, 'id' => $actor->id, 'system' => $actor->system];
        $this->ownershipEnvelope = CommentsOwnership::envelope();
    }

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
