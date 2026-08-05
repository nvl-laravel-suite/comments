<?php

declare(strict_types=1);

namespace Nvl\Comments\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nvl\Comments\Data\CommentActorData;

/**
 * Announces a durable report for moderation consumers.
 */
final class CommentReported implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $commentId,
        public readonly string $reportId,
        public readonly CommentActorData $actor,
        public readonly int $schemaVersion = 1,
    ) {}
}
