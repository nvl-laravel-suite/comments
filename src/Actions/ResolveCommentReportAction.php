<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\ResolveCommentReportData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Exceptions\StaleCommentException;
use Nvl\Comments\Models\CommentReport;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentLifecycleGuard;
use Nvl\Comments\Services\CommentMutationLock;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Services\CommentWorkflowGuard;
use Nvl\Comments\Support\CommentsConfiguration;

/**
 * Resolves or dismisses one report through moderator authorization.
 */
final readonly class ResolveCommentReportAction
{
    public function __construct(
        private CommentAccessService $access,
        private CommentWorkflowGuard $guard,
        private CommentLifecycleGuard $lifecycle,
        private CommentMutationLock $mutationLock,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
    ) {}

    /**
     * Resolve or dismiss one report after moderator authorization.
     */
    public function execute(
        CommentReport|string $report,
        ResolveCommentReportData $data,
        CommentActorData $actor,
    ): CommentReport {
        $reportId = $report instanceof CommentReport ? $report->id : $report;

        if ($data->status === CommentReportStatus::Open) {
            throw new InvalidCommentMutationException(
                'Report review must resolve or dismiss the report.',
            );
        }

        $this->guard->resolution($data);
        $this->lifecycle->expectedRevision($data->expectedRevision);
        $commentId = CommentReport::query()->findOrFail($reportId)->comment_id;

        return $this->mutationLock->execute(
            $commentId,
            fn (): CommentReport => DB::connection((new CommentReport)->getConnectionName())
                ->transaction(function () use (
                    $actor,
                    $commentId,
                    $data,
                    $reportId,
                ): CommentReport {
                    $comment = $this->reads->resolveById(
                        $commentId,
                        $actor,
                        CommentAudience::Management,
                        CommentAbility::Moderate,
                        withTrashed: true,
                        lockForUpdate: true,
                    );
                    $target = $this->targets->locate($comment);
                    $this->access->authorize(
                        CommentAbility::Moderate,
                        $actor,
                        $comment,
                        $target,
                        CommentAudience::Management,
                        context: ['operation' => 'resolve_report', 'report_id' => $reportId],
                    );

                    if ($comment->revision !== $data->expectedRevision) {
                        throw StaleCommentException::forComment($comment->id);
                    }

                    $report = CommentReport::query()
                        ->whereKey($reportId)
                        ->where('comment_id', $comment->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $report->setRelation('comment', $comment);

                    if ($report->status === $data->status
                        && $report->reviewed_by_type === $actor->type
                        && $report->reviewed_by === $actor->id
                        && $report->resolution === $data->resolution
                        && $report->reviewed_at !== null) {
                        return $report;
                    }

                    $wasOpen = $report->status === CommentReportStatus::Open;
                    $report->fill([
                        'status' => $data->status,
                        'reviewed_by_type' => $actor->type,
                        'reviewed_by' => $actor->id,
                        'resolution' => $data->resolution,
                        'reviewed_at' => now(),
                    ]);

                    if (! $report->save()) {
                        throw new InvalidCommentMutationException(
                            'The comment report review could not be saved.',
                        );
                    }

                    if ($wasOpen) {
                        $comment->open_report_count = max(
                            0,
                            $comment->open_report_count - 1,
                        );
                    }

                    $comment->revision++;

                    if (! $comment->save()) {
                        throw new InvalidCommentMutationException(
                            'The comment report review counters could not be updated.',
                        );
                    }

                    CommentChanged::dispatch(
                        $comment->id,
                        $comment->commentable_type,
                        $comment->commentable_id,
                        CommentChangeOperation::ReportReviewed,
                        $comment->revision,
                        $actor,
                    );

                    return $report->refresh()->load('comment');
                }, attempts: CommentsConfiguration::positiveInteger(
                    'comments.transactions.attempts',
                    3,
                )),
        );
    }
}
