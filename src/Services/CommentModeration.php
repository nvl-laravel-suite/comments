<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentReport;
use Nvl\Support\Enums\SystemActor;

/** Explicit host-authorized moderation operations. */
final readonly class CommentModeration
{
    public function __construct(
        private CommentModerationWriter $writer,
        private CommentReportWriter $reports,
        private CommentReportResolver $resolutions,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function moderate(
        Comment|string $comment,
        array $attributes,
        Authenticatable|SystemActor $actor,
    ): Comment {
        return $this->writer->moderate($comment, $attributes, CommentActor::from($actor));
    }

    /** @param array<string, mixed> $attributes */
    public function report(
        Comment|string $comment,
        array $attributes,
        Authenticatable $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): CommentReport {
        return $this->reports->report($comment, $attributes, CommentActor::from($actor), $audience);
    }

    /** @param array<string, mixed> $attributes */
    public function resolveReport(
        CommentReport|string $report,
        array $attributes,
        Authenticatable|SystemActor $actor,
    ): CommentReport {
        return $this->resolutions->resolveReport($report, $attributes, CommentActor::from($actor));
    }
}
