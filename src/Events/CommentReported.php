<?php

declare(strict_types=1);

namespace Nvl\Comments\Events;

use Nvl\Comments\Data\CommentActorData;
use Nvl\Support\Contracts\DomainEvent;

/**
 * Announces a durable report for moderation consumers.
 *
 * @api
 */
final class CommentReported implements DomainEvent
{
    public function __construct(
        public readonly string $commentId,
        public readonly string $reportId,
        public readonly CommentActorData $actor,
        public readonly int $schemaVersion = 1,
    ) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
