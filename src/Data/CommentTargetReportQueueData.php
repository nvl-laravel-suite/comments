<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use LogicException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentReport;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Privileged target queue row combining one actionable report with its comment.
 */
#[TypeScript]
final class CommentTargetReportQueueData extends Data
{
    use DataTransform;

    public function __construct(
        public readonly CommentReportManagementData $report,
        public readonly CommentManagementData $comment,
    ) {}

    /**
     * Build a target queue row from a report whose comment was eagerly loaded.
     */
    public static function fromModel(
        CommentReport $report,
        CommentManagementData $commentData,
        bool $includeActorIdentity = false,
    ): self {
        if (! $report->relationLoaded('comment')) {
            throw new LogicException(
                'Target report queue projections require the comment relation to be eager loaded.',
            );
        }

        $comment = $report->getRelation('comment');

        if (! $comment instanceof Comment) {
            throw new LogicException('A target report queue row requires its owning comment.');
        }

        if ($commentData->id !== $comment->id) {
            throw new LogicException(
                'A target report queue projection must match its owning comment.',
            );
        }

        return new self(
            report: CommentReportManagementData::fromModel(
                $report,
                $includeActorIdentity,
            ),
            comment: $commentData,
        );
    }
}
