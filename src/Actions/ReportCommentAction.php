<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\ReportCommentData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Comments\Events\CommentReported;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentReport;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentMutationLock;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Services\CommentWorkflowGuard;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentsConfiguration;

/**
 * Creates or refreshes one actor's report without count inflation.
 */
final readonly class ReportCommentAction
{
    public function __construct(
        private CommentAccessService $access,
        private CommentWorkflowGuard $guard,
        private CommentMutationLock $mutationLock,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
    ) {}

    /**
     * Create or refresh one identified actor's report for a comment.
     */
    public function execute(
        Comment|string $comment,
        ReportCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): CommentReport {
        $commentId = $comment instanceof Comment ? $comment->id : $comment;

        if ($actor->type === null
            || $actor->type === ''
            || $actor->id === null
            || $actor->id === '') {
            throw new AuthorizationException('Reports require an identified actor.');
        }

        $this->guard->report($data);
        $reporterIdentityHash = CommentIdentity::pair($actor->type, $actor->id);

        return $this->mutationLock->execute(
            $commentId,
            fn (): CommentReport => DB::connection((new Comment)->getConnectionName())
                ->transaction(function () use (
                    $actor,
                    $audience,
                    $commentId,
                    $data,
                    $reporterIdentityHash,
                ): CommentReport {
                    $comment = $this->reads->resolveById(
                        $commentId,
                        $actor,
                        $audience,
                        CommentAbility::Report,
                        withTrashed: false,
                        lockForUpdate: true,
                    );
                    $target = $this->targets->locate($comment);
                    $this->access->authorize(
                        CommentAbility::Report,
                        $actor,
                        $comment,
                        $target,
                        $audience,
                        asNotFound: $audience !== CommentAudience::Management,
                    );
                    $report = CommentReport::query()->where([
                        'comment_id' => $comment->id,
                        'reporter_identity_hash' => $reporterIdentityHash,
                    ])->lockForUpdate()->first();

                    if ($report !== null
                        && (! hash_equals($report->reporter_type, $actor->type)
                            || ! hash_equals($report->reporter_id, $actor->id))) {
                        throw new InvalidCommentMutationException(
                            'The stored report identity fingerprint is inconsistent.',
                        );
                    }

                    if ($report !== null
                        && $report->status === CommentReportStatus::Open
                        && $report->reason === $data->reason
                        && $report->details === $data->details
                        && $report->reviewed_by_type === null
                        && $report->reviewed_by === null
                        && $report->resolution === null
                        && $report->reviewed_at === null) {
                        return $report;
                    }

                    $created = $report === null;
                    $wasOpen = $report?->status === CommentReportStatus::Open;
                    $report ??= new CommentReport;
                    $report->fill([
                        'comment_id' => $comment->id,
                        'reporter_type' => $actor->type,
                        'reporter_id' => $actor->id,
                        'reason' => $data->reason,
                        'details' => $data->details,
                        'status' => CommentReportStatus::Open,
                        'reviewed_by_type' => null,
                        'reviewed_by' => null,
                        'resolution' => null,
                        'reviewed_at' => null,
                    ]);

                    if (! $report->save()) {
                        throw new InvalidCommentMutationException(
                            'The comment report could not be saved.',
                        );
                    }

                    if ($created) {
                        $saved = $comment->fill([
                            'report_count' => $comment->report_count + 1,
                            'open_report_count' => $comment->open_report_count + 1,
                        ])->save();
                    } elseif (! $wasOpen) {
                        $saved = $comment->fill([
                            'open_report_count' => $comment->open_report_count + 1,
                        ])->save();
                    } else {
                        $saved = true;
                    }

                    if (! $saved) {
                        throw new InvalidCommentMutationException(
                            'The comment report counters could not be updated.',
                        );
                    }

                    CommentReported::dispatch($comment->id, $report->id, $actor);

                    return $report->refresh();
                }, attempts: CommentsConfiguration::positiveInteger(
                    'comments.transactions.attempts',
                    3,
                )),
        );
    }
}
