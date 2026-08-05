<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Comments\Models\CommentReport;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Privileged report representation exposed only after moderator authorization.
 */
#[TypeScript]
final class CommentReportManagementData extends Data
{
    use DataTransform;

    public function __construct(
        public readonly string $id,
        public readonly string $commentId,
        public readonly string|Optional|null $reporterType,
        public readonly string|Optional|null $reporterId,
        public readonly string $reason,
        public readonly ?string $details,
        public readonly CommentReportStatus $status,
        public readonly string|Optional|null $reviewedByType,
        public readonly string|Optional|null $reviewedBy,
        public readonly ?string $resolution,
        public readonly ?string $reviewedAt,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    /**
     * Build the privileged projection from a persisted report.
     */
    public static function fromModel(
        CommentReport $report,
        bool $includeActorIdentity = false,
    ): self {
        $omitted = Optional::create();

        return new self(
            id: $report->id,
            commentId: $report->comment_id,
            reporterType: $includeActorIdentity ? $report->reporter_type : $omitted,
            reporterId: $includeActorIdentity ? $report->reporter_id : $omitted,
            reason: $report->reason,
            details: $report->details,
            status: $report->status,
            reviewedByType: $includeActorIdentity
                ? $report->reviewed_by_type
                : $omitted,
            reviewedBy: $includeActorIdentity ? $report->reviewed_by : $omitted,
            resolution: $report->resolution,
            reviewedAt: $report->reviewed_at?->format(DATE_ATOM),
            createdAt: $report->created_at->format(DATE_ATOM),
            updatedAt: $report->updated_at->format(DATE_ATOM),
        );
    }
}
