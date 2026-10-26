<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Support\Facades\DB;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Exceptions\StaleCommentException;
use Nvl\Comments\Models\CommentReport;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/**
 * Resolves or dismisses one report through moderator authorization.
 *
 * @internal
 */
final readonly class CommentReportResolver
{
    public function __construct(
        private CommentAccessService $access,
        private CommentWorkflowGuard $guard,
        private CommentLifecycleGuard $lifecycle,
        private CommentMutationLock $mutationLock,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
        private OwnershipBoundary $boundary,
        private DomainEventDispatcher $domainEvents,
    ) {}

    /**
     * Resolve or dismiss one report after moderator authorization.
     *
     * @param  array<string, mixed>  $data
     */
    public function resolveReport(
        CommentReport|string $report,
        array $data,
        CommentActor $actor,
    ): CommentReport {
        if (array_diff(array_keys($data), ['status', 'expected_revision', 'resolution']) !== []
            || ! is_int($data['expected_revision'] ?? null)
            || $data['expected_revision'] < 1
            || ! is_string($data['resolution'] ?? null)) {
            throw new InvalidCommentMutationException('Comment report resolution attributes are invalid.');
        }
        if (is_string($data['status'] ?? null)) {
            $data['status'] = CommentReportStatus::tryFrom($data['status']);
        }
        if (! ($data['status'] ?? null) instanceof CommentReportStatus) {
            throw new InvalidCommentMutationException('Comment report resolution status is invalid.');
        }
        $reportId = $report instanceof CommentReport ? $report->id : $report;

        if ($data['status'] === CommentReportStatus::Open) {
            throw new InvalidCommentMutationException(
                'Report review must resolve or dismiss the report.',
            );
        }

        $this->guard->resolution($data);
        $this->lifecycle->expectedRevision($data['expected_revision']);
        $commentId = $this->boundary->query(CommentReport::query(), 'comments.reports')
            ->findOrFail($reportId)->comment_id;

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

                    if ($comment->revision !== $data['expected_revision']) {
                        throw StaleCommentException::forComment($comment->id);
                    }

                    $report = $this->boundary->query(CommentReport::query(), 'comments.reports')
                        ->whereKey($reportId)
                        ->where('comment_id', $comment->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $report->setRelation('comment', $comment);

                    if ($report->status === $data['status']
                        && $report->reviewed_by_type === $actor->type
                        && $report->reviewed_by === $actor->id
                        && $report->resolution === $data['resolution']
                        && $report->reviewed_at !== null) {
                        return $report;
                    }

                    $wasOpen = $report->status === CommentReportStatus::Open;
                    $report->fill([
                        'status' => $data['status'],
                        'reviewed_by_type' => $actor->type,
                        'reviewed_by' => $actor->id,
                        'resolution' => $data['resolution'],
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

                    $this->domainEvents->dispatch(new CommentChanged(
                        $comment->id,
                        $comment->commentable_type,
                        $comment->commentable_id,
                        CommentChangeOperation::ReportReviewed,
                        $comment->revision,
                        $actor,
                    ), $comment->getConnection());

                    return $report->refresh()->load('comment');
                }, attempts: CommentsConfiguration::positiveInteger(
                    'nvl-comments.transactions.attempts',
                    3,
                )),
        );
    }
}
