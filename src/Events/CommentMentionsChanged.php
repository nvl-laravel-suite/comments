<?php

declare(strict_types=1);

namespace Nvl\Comments\Events;

use InvalidArgumentException;
use Nvl\Comments\Data\CommentMentionChangeData;
use Nvl\Comments\Ownership\CommentsOwnership;
use Nvl\Support\Contracts\DomainEvent;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/**
 * Announces one durable bounded diff of registered comment mention identities.
 *
 * @api
 */
final class CommentMentionsChanged implements DomainEvent, OwnershipQueuedJob
{
    private readonly OwnershipJobEnvelope $ownershipEnvelope;

    /** Return the scalar authority captured when this fact occurred. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->ownershipEnvelope;
    }

    /**
     * Create one after-commit mention change fact.
     *
     * @param  list<CommentMentionChangeData>  $added
     * @param  list<CommentMentionChangeData>  $removed
     */
    public function __construct(
        public readonly string $commentId,
        public readonly string $targetType,
        public readonly string $targetId,
        public readonly int $revision,
        public readonly array $added,
        public readonly array $removed,
        public readonly int $schemaVersion = 1,
    ) {
        $this->ownershipEnvelope = CommentsOwnership::envelope();
        if (count($this->added) > 100 || count($this->removed) > 100) {
            throw new InvalidArgumentException('Comment mention change facts exceed hard bounds.');
        }

        foreach ([...$this->added, ...$this->removed] as $change) {
            self::validateChange($change);
        }
    }

    /**
     * Validate one runtime event value at the public construction boundary.
     */
    private static function validateChange(mixed $change): void
    {
        if (! $change instanceof CommentMentionChangeData) {
            throw new InvalidArgumentException('Comment mention change facts are invalid.');
        }
    }

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
