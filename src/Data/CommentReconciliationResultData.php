<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;

/**
 * Summarizes one read-only audit or bounded repair of persisted comment state.
 */
final class CommentReconciliationResultData extends Data
{
    use DataTransform;

    public function __construct(
        public readonly bool $dryRun,
        public readonly ?string $target,
        public readonly int $scanned,
        public readonly int $drifted,
        public readonly int $repaired,
        public readonly int $remaining,
        public readonly int $replyCountMismatches,
        public readonly int $reactionCountMismatches,
        public readonly int $reportCountMismatches,
        public readonly int $openReportCountMismatches,
        public readonly int $threadMismatches,
        public readonly int $unrepairableThreadMismatches,
        public readonly int $identityFingerprintMismatches,
        public readonly int $missingTargetComments,
        public readonly int $invalidAttachmentAssociations,
        public readonly bool $healthy,
        public readonly int $missingMetadataIndexValues = 0,
        public readonly int $staleMetadataIndexValues = 0,
        public readonly int $documentMentionMismatches = 0,
        public readonly int $duplicateMentionIdentities = 0,
        public readonly int $invalidMentionSnapshots = 0,
        public readonly int $orphanMentionRows = 0,
        public readonly int $bodyProjectionMismatches = 0,
    ) {}
}
