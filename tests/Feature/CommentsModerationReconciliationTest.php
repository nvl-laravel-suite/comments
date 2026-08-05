<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Nvl\Comments\Actions\CreateCommentAction;
use Nvl\Comments\Actions\DeleteCommentAction;
use Nvl\Comments\Actions\ListModerationCommentsAction;
use Nvl\Comments\Actions\ListTargetCommentReportsAction;
use Nvl\Comments\Actions\ModerateCommentAction;
use Nvl\Comments\Actions\ReportCommentAction;
use Nvl\Comments\Actions\ResolveCommentReportAction;
use Nvl\Comments\Actions\SetCommentReactionAction;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Data\Mutations\DeleteCommentData;
use Nvl\Comments\Data\Mutations\ModerateCommentData;
use Nvl\Comments\Data\Mutations\ReportCommentData;
use Nvl\Comments\Data\Mutations\ResolveCommentReportData;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Events\CommentReported;
use Nvl\Comments\Exceptions\StaleCommentException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentReport;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Filterable\Data\FilterCriterion;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;

/**
 * @param  array<string, bool|int|string>  $options
 * @return array{int, array<string, mixed>}
 */
function runCommentsV1Reconciliation(array $options = []): array
{
    $exitCode = Artisan::call('nvl:comments:reconcile', [
        '--format' => 'json',
        ...$options,
    ]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($result)) {
        throw new RuntimeException('Comments reconciliation did not return a JSON object.');
    }

    return [$exitCode, $result];
}

it('keeps moderation queues actionable target-bound filterable and deleted-aware', function (): void {
    $this->travelTo(Carbon::parse('2026-07-30 08:00:00 UTC'));
    $firstTarget = TestCommentTarget::query()->create(['name' => 'First article']);
    $secondTarget = TestCommentTarget::query()->create(['name' => 'Second article']);
    $author = new CommentActorData('member', 'author');
    $create = app(CreateCommentAction::class);

    config()->set('comments.moderation.new_status', CommentStatus::Pending->value);
    $pending = $create->execute(
        $firstTarget,
        new CreateCommentData('Pending review'),
        $author,
    );

    $this->travel(1)->minute();
    config()->set('comments.moderation.new_status', CommentStatus::Spam->value);
    $spam = $create->execute(
        $firstTarget,
        new CreateCommentData('Spam review'),
        $author,
    );

    $this->travel(1)->minute();
    config()->set('comments.moderation.new_status', CommentStatus::Approved->value);
    $clean = $create->execute(
        $firstTarget,
        new CreateCommentData('No moderation work'),
        $author,
    );
    $reported = $create->execute(
        $firstTarget,
        new CreateCommentData('Open report'),
        $author,
    );
    $firstOpenReport = app(ReportCommentAction::class)->execute(
        $reported,
        new ReportCommentData('abuse', 'First actionable report'),
        new CommentActorData('member', 'reporter-one'),
    );

    $this->travel(1)->minute();
    $resolvedComment = $create->execute(
        $firstTarget,
        new CreateCommentData('Closed report'),
        $author,
    );
    $resolvedReport = app(ReportCommentAction::class)->execute(
        $resolvedComment,
        new ReportCommentData('spam', 'Already reviewed'),
        new CommentActorData('member', 'reporter-two'),
    );
    app(ResolveCommentReportAction::class)->execute(
        $resolvedReport,
        new ResolveCommentReportData(
            CommentReportStatus::Resolved,
            $resolvedComment->refresh()->revision,
            'No longer actionable',
        ),
        CommentActorData::system(),
    );

    $this->travel(1)->minute();
    $deletedEvidence = $create->execute(
        $firstTarget,
        new CreateCommentData('Deleted evidence'),
        $author,
    );
    $latestOpenReport = app(ReportCommentAction::class)->execute(
        $deletedEvidence,
        new ReportCommentData('abuse', 'Preserve this evidence'),
        new CommentActorData('member', 'reporter-three'),
    );
    app(DeleteCommentAction::class)->execute(
        $deletedEvidence,
        new DeleteCommentData($deletedEvidence->revision),
        $author,
    );

    $this->travel(1)->minute();
    config()->set('comments.moderation.new_status', CommentStatus::Pending->value);
    $otherTargetPending = $create->execute(
        $secondTarget,
        new CreateCommentData('Other target pending'),
        $author,
    );
    config()->set('comments.moderation.new_status', CommentStatus::Approved->value);
    $otherTargetReported = $create->execute(
        $secondTarget,
        new CreateCommentData('Other target report'),
        $author,
    );
    app(ReportCommentAction::class)->execute(
        $otherTargetReported,
        new ReportCommentData('abuse', 'Other target evidence'),
        new CommentActorData('member', 'reporter-four'),
    );

    $queue = app(ListModerationCommentsAction::class)->execute(
        $firstTarget,
        CommentActorData::system(),
    );
    $queueIds = array_map(
        static fn (Comment $comment): string => $comment->id,
        $queue->items(),
    );

    expect($queueIds)->toHaveCount(4)
        ->toContain($pending->id, $spam->id, $reported->id, $deletedEvidence->id)
        ->not->toContain(
            $clean->id,
            $resolvedComment->id,
            $otherTargetPending->id,
            $otherTargetReported->id,
        );

    $openReportQueue = app(ListModerationCommentsAction::class)->execute(
        $firstTarget,
        CommentActorData::system(),
        filterSet: new FilterSet(filters: [
            new FilterCriterion('open_reports', FilterOperator::Gt, 0),
        ]),
    );

    expect(array_map(
        static fn (Comment $comment): string => $comment->id,
        $openReportQueue->items(),
    ))->toBe([$deletedEvidence->id, $reported->id]);

    $targetReports = app(ListTargetCommentReportsAction::class)->execute(
        $firstTarget,
        CommentActorData::system(),
    );
    $targetReportIds = array_map(
        static fn (CommentReport $report): string => $report->id,
        $targetReports->items(),
    );
    $deletedReport = collect($targetReports->items())->firstWhere(
        'id',
        $latestOpenReport->id,
    );

    expect($targetReportIds)->toBe([$latestOpenReport->id, $firstOpenReport->id])
        ->and($deletedReport)->toBeInstanceOf(CommentReport::class)
        ->and($deletedReport->comment->trashed())->toBeTrue();

    $closedReports = app(ListTargetCommentReportsAction::class)->execute(
        $firstTarget,
        CommentActorData::system(),
        filterSet: new FilterSet(filters: [
            new FilterCriterion(
                'status',
                FilterOperator::Equals,
                CommentReportStatus::Resolved->value,
            ),
        ]),
    );

    expect($closedReports->total())->toBe(1)
        ->and($closedReports->items()[0]->id)->toBe($resolvedReport->id);
});

it('preserves lifetime report totals across reopen review and exact no-op transitions', function (): void {
    $this->travelTo(Carbon::parse('2026-07-30 09:00:00 UTC'));
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $author = new CommentActorData('member', 'author');
    $reporter = new CommentActorData('member', 'reporter');
    $moderator = CommentActorData::system();
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Review state machine'),
        $author,
    );
    Event::fake([CommentChanged::class, CommentReported::class]);

    $report = app(ReportCommentAction::class)->execute(
        $comment,
        new ReportCommentData('abuse', 'Stable details'),
        $reporter,
    );
    $openUpdatedAt = $report->updated_at->toISOString();

    $this->travel(1)->minute();
    $sameOpenReport = app(ReportCommentAction::class)->execute(
        $comment,
        new ReportCommentData('abuse', 'Stable details'),
        $reporter,
    );

    expect($sameOpenReport->id)->toBe($report->id)
        ->and($sameOpenReport->updated_at->toISOString())->toBe($openUpdatedAt)
        ->and($comment->refresh()->report_count)->toBe(1)
        ->and($comment->open_report_count)->toBe(1);
    Event::assertDispatched(CommentReported::class, 1);

    $this->travel(1)->minute();
    expect(fn () => app(ResolveCommentReportAction::class)->execute(
        $report,
        new ResolveCommentReportData(
            CommentReportStatus::Resolved,
            $comment->refresh()->revision + 1,
            'Stale review',
        ),
        $moderator,
    ))->toThrow(StaleCommentException::class);
    expect($report->refresh()->status)->toBe(CommentReportStatus::Open)
        ->and($comment->refresh()->open_report_count)->toBe(1);
    Event::assertNotDispatched(CommentChanged::class);

    $resolved = app(ResolveCommentReportAction::class)->execute(
        $report,
        new ResolveCommentReportData(
            CommentReportStatus::Resolved,
            $comment->refresh()->revision,
            'Reviewed once',
        ),
        $moderator,
    );
    $resolvedUpdatedAt = $resolved->updated_at->toISOString();
    $resolvedReviewedAt = $resolved->reviewed_at?->toISOString();

    $this->travel(1)->minute();
    $sameResolved = app(ResolveCommentReportAction::class)->execute(
        $resolved,
        new ResolveCommentReportData(
            CommentReportStatus::Resolved,
            $comment->refresh()->revision,
            'Reviewed once',
        ),
        $moderator,
    );

    expect($sameResolved->updated_at->toISOString())->toBe($resolvedUpdatedAt)
        ->and($sameResolved->reviewed_at?->toISOString())->toBe($resolvedReviewedAt)
        ->and($comment->refresh()->report_count)->toBe(1)
        ->and($comment->open_report_count)->toBe(0);
    Event::assertDispatched(CommentChanged::class, 1);

    $this->travel(1)->minute();
    $reopened = app(ReportCommentAction::class)->execute(
        $comment,
        new ReportCommentData('abuse', 'Stable details'),
        $reporter,
    );

    expect($reopened->status)->toBe(CommentReportStatus::Open)
        ->and($reopened->reviewed_at)->toBeNull()
        ->and($reopened->resolution)->toBeNull()
        ->and($comment->refresh()->report_count)->toBe(1)
        ->and($comment->open_report_count)->toBe(1);
    Event::assertDispatched(CommentReported::class, 2);

    $this->travel(1)->minute();
    $dismissed = app(ResolveCommentReportAction::class)->execute(
        $reopened,
        new ResolveCommentReportData(
            CommentReportStatus::Dismissed,
            $comment->refresh()->revision,
            'Dismissed once',
        ),
        $moderator,
    );
    $dismissedUpdatedAt = $dismissed->updated_at->toISOString();
    $dismissedReviewedAt = $dismissed->reviewed_at?->toISOString();

    $this->travel(1)->minute();
    $sameDismissed = app(ResolveCommentReportAction::class)->execute(
        $dismissed,
        new ResolveCommentReportData(
            CommentReportStatus::Dismissed,
            $comment->refresh()->revision,
            'Dismissed once',
        ),
        $moderator,
    );

    expect($sameDismissed->updated_at->toISOString())->toBe($dismissedUpdatedAt)
        ->and($sameDismissed->reviewed_at?->toISOString())->toBe($dismissedReviewedAt)
        ->and($comment->refresh()->report_count)->toBe(1)
        ->and($comment->open_report_count)->toBe(0)
        ->and(CommentReport::query()->count())->toBe(1);
    Event::assertDispatched(CommentChanged::class, 2);

    $this->travel(1)->minute();
    $moderated = app(ModerateCommentAction::class)->execute(
        $comment,
        new ModerateCommentData(
            CommentStatus::Hidden,
            $comment->revision,
            reason: 'Durable moderation',
            pinned: true,
        ),
        $moderator,
    );
    $moderatedAt = $moderated->moderated_at?->toISOString();

    $this->travel(1)->minute();
    $sameModeration = app(ModerateCommentAction::class)->execute(
        $moderated,
        new ModerateCommentData(
            CommentStatus::Hidden,
            $moderated->revision,
            reason: 'Durable moderation',
            pinned: true,
        ),
        $moderator,
    );

    expect($sameModeration->revision)->toBe($moderated->revision)
        ->and($sameModeration->moderated_at?->toISOString())->toBe($moderatedAt);
    Event::assertDispatched(CommentChanged::class, 3);
    Event::assertDispatched(
        CommentChanged::class,
        static fn (CommentChanged $event): bool => $event->operation
            === CommentChangeOperation::Moderated,
    );
});

it('audits without mutation then repairs safe drift once without replaying user events', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $actor = new CommentActorData('member', 'author');
    $create = app(CreateCommentAction::class);
    $root = $create->execute(
        $target,
        new CreateCommentData('Root'),
        $actor,
    );
    $reply = $create->execute(
        $target,
        new CreateCommentData('Reply', parentId: $root->id),
        $actor,
    );
    app(SetCommentReactionAction::class)->execute(
        $root,
        'helpful',
        true,
        $actor,
    );
    app(ReportCommentAction::class)->execute(
        $root,
        new ReportCommentData('abuse', 'Counter source'),
        new CommentActorData('member', 'reporter'),
    );

    Comment::query()->whereKey($root->id)->update([
        'reply_count' => 9,
        'reaction_count' => 8,
        'report_count' => 7,
        'open_report_count' => 6,
    ]);
    Comment::query()->whereKey($reply->id)->update([
        'root_id' => null,
        'depth' => 5,
    ]);
    $corruptRoot = Comment::query()->findOrFail($root->id);
    $corruptReply = Comment::query()->findOrFail($reply->id);
    Event::fake([CommentChanged::class, CommentReported::class]);

    [$dryRunExitCode, $dryRun] = runCommentsV1Reconciliation([
        '--target' => "article:{$target->id}",
    ]);

    expect($dryRunExitCode)->toBe(0)
        ->and($dryRun['dryRun'])->toBeTrue()
        ->and($dryRun['scanned'])->toBe(2)
        ->and($dryRun['drifted'])->toBe(2)
        ->and($dryRun['repaired'])->toBe(0)
        ->and($dryRun['replyCountMismatches'])->toBe(1)
        ->and($dryRun['reactionCountMismatches'])->toBe(1)
        ->and($dryRun['reportCountMismatches'])->toBe(1)
        ->and($dryRun['openReportCountMismatches'])->toBe(1)
        ->and($dryRun['threadMismatches'])->toBe(1)
        ->and($dryRun['healthy'])->toBeFalse()
        ->and(Comment::query()->findOrFail($root->id)->only([
            'reply_count',
            'reaction_count',
            'report_count',
            'open_report_count',
        ]))->toBe($corruptRoot->only([
            'reply_count',
            'reaction_count',
            'report_count',
            'open_report_count',
        ]))
        ->and(Comment::query()->findOrFail($reply->id)->only([
            'root_id',
            'depth',
        ]))->toBe($corruptReply->only([
            'root_id',
            'depth',
        ]));

    [$repairExitCode, $repair] = runCommentsV1Reconciliation([
        '--repair' => true,
        '--strict' => true,
        '--target' => "article:{$target->id}",
        '--chunk' => 1,
    ]);
    $repairedRoot = Comment::query()->findOrFail($root->id);
    $repairedReply = Comment::query()->findOrFail($reply->id);

    expect($repairExitCode)->toBe(0)
        ->and($repair['dryRun'])->toBeFalse()
        ->and($repair['drifted'])->toBe(2)
        ->and($repair['repaired'])->toBe(2)
        ->and($repair['remaining'])->toBe(0)
        ->and($repair['healthy'])->toBeTrue()
        ->and($repairedRoot->reply_count)->toBe(1)
        ->and($repairedRoot->reaction_count)->toBe(1)
        ->and($repairedRoot->report_count)->toBe(1)
        ->and($repairedRoot->open_report_count)->toBe(1)
        ->and($repairedReply->root_id)->toBe($root->id)
        ->and($repairedReply->depth)->toBe(1);

    [$verifiedExitCode, $verified] = runCommentsV1Reconciliation([
        '--strict' => true,
        '--target' => "article:{$target->id}",
    ]);

    expect($verifiedExitCode)->toBe(0)
        ->and($verified['dryRun'])->toBeTrue()
        ->and($verified['drifted'])->toBe(0)
        ->and($verified['remaining'])->toBe(0)
        ->and($verified['healthy'])->toBeTrue();

    expect(Artisan::call('nvl:comments:reconcile', [
        '--target' => "article:{$target->id}",
    ]))->toBe(0)
        ->and(Artisan::output())->toContain(
            'Metric',
            'Comments scanned',
            'Healthy',
        );
    Event::assertNotDispatched(CommentChanged::class);
    Event::assertNotDispatched(CommentReported::class);
});

it('fails strict repair while unsafe missing-target damage remains untouched', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Disposable article']);
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Orphaned evidence'),
        new CommentActorData('member', 'author'),
    );
    TestCommentTarget::query()->whereKey($target->id)->delete();
    Event::fake([CommentChanged::class, CommentReported::class]);

    [$exitCode, $result] = runCommentsV1Reconciliation([
        '--repair' => true,
        '--strict' => true,
        '--chunk' => 1,
    ]);

    expect($exitCode)->toBe(1)
        ->and($result['dryRun'])->toBeFalse()
        ->and($result['drifted'])->toBe(1)
        ->and($result['repaired'])->toBe(0)
        ->and($result['remaining'])->toBe(1)
        ->and($result['missingTargetComments'])->toBe(1)
        ->and($result['healthy'])->toBeFalse()
        ->and(Comment::query()->findOrFail($comment->id)->commentable_id)
        ->toBe($target->id);
    Event::assertNotDispatched(CommentChanged::class);
    Event::assertNotDispatched(CommentReported::class);
});

it('diagnoses comment hierarchy cycles without attempting destructive repair', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Cyclic article']);
    $actor = new CommentActorData('member', 'author');
    $create = app(CreateCommentAction::class);
    $root = $create->execute(
        $target,
        new CreateCommentData('Cycle root'),
        $actor,
    );
    $reply = $create->execute(
        $target,
        new CreateCommentData('Cycle reply', parentId: $root->id),
        $actor,
    );
    Comment::query()->whereKey($root->id)->update([
        'parent_id' => $reply->id,
    ]);
    Comment::query()->whereKey($reply->id)->update([
        'reply_count' => 1,
    ]);

    [$exitCode, $result] = runCommentsV1Reconciliation([
        '--repair' => true,
        '--strict' => true,
        '--target' => "article:{$target->id}",
        '--chunk' => 1,
    ]);

    expect($exitCode)->toBe(1)
        ->and($result['scanned'])->toBe(2)
        ->and($result['drifted'])->toBe(2)
        ->and($result['repaired'])->toBe(0)
        ->and($result['remaining'])->toBe(2)
        ->and($result['threadMismatches'])->toBe(2)
        ->and($result['unrepairableThreadMismatches'])->toBe(2)
        ->and($result['healthy'])->toBeFalse()
        ->and(Comment::query()->findOrFail($root->id)->parent_id)->toBe($reply->id)
        ->and(Comment::query()->findOrFail($reply->id)->parent_id)->toBe($root->id);
});

it('diagnoses dangling comment attachment associations without deleting evidence', function (): void {
    $missingCommentId = Str::uuid()->toString();
    $media = Media::factory()->create();
    $association = MediaAssociation::query()->create([
        'media_id' => $media->id,
        'associable_type' => (new Comment)->getMorphClass(),
        'associable_id' => $missingCommentId,
        'collection' => 'attachments',
        'order' => 0,
        'is_active' => true,
    ]);

    [$exitCode, $result] = runCommentsV1Reconciliation([
        '--repair' => true,
        '--strict' => true,
        '--chunk' => 1,
    ]);

    expect($exitCode)->toBe(1)
        ->and($result['scanned'])->toBe(0)
        ->and($result['drifted'])->toBe(1)
        ->and($result['repaired'])->toBe(0)
        ->and($result['remaining'])->toBe(1)
        ->and($result['invalidAttachmentAssociations'])->toBe(1)
        ->and($result['healthy'])->toBeFalse()
        ->and(MediaAssociation::query()->whereKey($association->id)->exists())
        ->toBeTrue()
        ->and(Media::query()->whereKey($media->id)->exists())->toBeTrue();
});

it('matches attachment owner and morph identities byte for byte across database collations', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Exact attachment identities']);
    $created = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Canonical attachment owner'),
        new CommentActorData('member', 'exact-owner'),
    );
    $canonicalCommentId = 'abcdef01-2345-4678-9abc-def012345678';
    Comment::query()->whereKey($created->id)->update(['id' => $canonicalCommentId]);
    $comment = Comment::query()->findOrFail($canonicalCommentId);
    $canonicalMedia = Media::factory()->create([
        'is_public' => false,
        'mime_type' => 'application/pdf',
        'size' => 1024,
    ]);
    MediaAssociation::query()->create([
        'media_id' => $canonicalMedia->id,
        'associable_type' => $comment->getMorphClass(),
        'associable_id' => $comment->id,
        'collection' => 'attachments',
        'order' => 0,
        'is_active' => true,
    ]);
    $caseDistinctOwner = MediaAssociation::query()->create([
        'media_id' => Media::factory()->create()->id,
        'associable_type' => $comment->getMorphClass(),
        'associable_id' => Str::upper($comment->id),
        'collection' => 'attachments',
        'order' => 0,
        'is_active' => true,
    ]);
    $caseDistinctMorph = MediaAssociation::query()->create([
        'media_id' => Media::factory()->create()->id,
        'associable_type' => Str::upper($comment->getMorphClass()),
        'associable_id' => Str::uuid()->toString(),
        'collection' => 'attachments',
        'order' => 0,
        'is_active' => true,
    ]);

    [$exitCode, $result] = runCommentsV1Reconciliation([
        '--strict' => true,
        '--chunk' => 1,
    ]);

    expect($exitCode)->toBe(1)
        ->and($result['scanned'])->toBe(1)
        ->and($result['drifted'])->toBe(1)
        ->and($result['invalidAttachmentAssociations'])->toBe(1)
        ->and($result['healthy'])->toBeFalse()
        ->and(MediaAssociation::query()->whereKey($caseDistinctOwner->id)->exists())
        ->toBeTrue()
        ->and(MediaAssociation::query()->whereKey($caseDistinctMorph->id)->exists())
        ->toBeTrue();
});

it('diagnoses attachment drift on an anonymized comment', function (bool $active): void {
    $target = TestCommentTarget::query()->create(['name' => 'Anonymized attachment drift']);
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Content already erased elsewhere'),
        new CommentActorData('member', 'erased-author'),
    );
    $media = Media::factory()->create();
    $association = MediaAssociation::query()->create([
        'media_id' => $media->id,
        'associable_type' => $comment->getMorphClass(),
        'associable_id' => $comment->id,
        'collection' => 'attachments',
        'order' => 0,
        'is_active' => $active,
    ]);
    Comment::query()->whereKey($comment->id)->update([
        'body' => '',
        'actor_type' => null,
        'actor_id' => null,
        'anonymized_at' => now(),
    ]);
    $comment->delete();

    [$exitCode, $result] = runCommentsV1Reconciliation([
        '--strict' => true,
        '--target' => "article:{$target->id}",
    ]);

    expect($exitCode)->toBe(1)
        ->and($result['scanned'])->toBe(1)
        ->and($result['invalidAttachmentAssociations'])->toBe(1)
        ->and($result['remaining'])->toBe(1)
        ->and($result['healthy'])->toBeFalse()
        ->and(MediaAssociation::query()->whereKey($association->id)->exists())
        ->toBeTrue();
})->with([
    'active association' => true,
    'inactive association' => false,
]);
