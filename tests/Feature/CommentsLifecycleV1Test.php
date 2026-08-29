<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Nvl\Comments\Actions\AnonymizeCommentAction;
use Nvl\Comments\Actions\AttachCommentMediaAction;
use Nvl\Comments\Actions\CreateCommentAction;
use Nvl\Comments\Actions\DeleteCommentAction;
use Nvl\Comments\Actions\DetachCommentMediaAction;
use Nvl\Comments\Actions\FindLatestTargetCommentAction;
use Nvl\Comments\Actions\ModerateCommentAction;
use Nvl\Comments\Actions\ReportCommentAction;
use Nvl\Comments\Actions\ResolveCommentReportAction;
use Nvl\Comments\Actions\RestoreCommentAction;
use Nvl\Comments\Actions\RestoreCommentRevisionAction;
use Nvl\Comments\Actions\SetCommentReactionAction;
use Nvl\Comments\Actions\UpdateCommentAction;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\AnonymizeCommentData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Data\Mutations\DeleteCommentData;
use Nvl\Comments\Data\Mutations\ModerateCommentData;
use Nvl\Comments\Data\Mutations\ReportCommentData;
use Nvl\Comments\Data\Mutations\ResolveCommentReportData;
use Nvl\Comments\Data\Mutations\RestoreCommentData;
use Nvl\Comments\Data\Mutations\RestoreCommentRevisionData;
use Nvl\Comments\Data\Mutations\UpdateCommentData;
use Nvl\Comments\Data\Queries\CommentSelectorData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Events\CommentReactionChanged;
use Nvl\Comments\Events\CommentReported;
use Nvl\Comments\Exceptions\CommentIdempotencyConflictException;
use Nvl\Comments\Exceptions\InvalidCommentLifecycleException;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Exceptions\StaleCommentException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentMetadataValue;
use Nvl\Comments\Models\CommentReaction;
use Nvl\Comments\Models\CommentReport;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Services\CommentMetadataRegistry;
use Nvl\Comments\Services\CommentMutationLock;
use Nvl\Comments\Services\CommentProjectionFactory;
use Nvl\Comments\Tests\Fixtures\TestCommentMetadataSchema;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;

it('delivers every user event after the root commit and discards rollback events', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'After-commit article']);
    $author = new CommentActorData('member', 'event-author');
    $reporter = new CommentActorData('member', 'event-reporter');
    $changed = [];
    $reactions = [];
    $reported = [];
    Event::listen(
        CommentChanged::class,
        static function (CommentChanged $event) use (&$changed): void {
            $changed[] = $event;
        },
    );
    Event::listen(
        CommentReactionChanged::class,
        static function (CommentReactionChanged $event) use (&$reactions): void {
            $reactions[] = $event;
        },
    );
    Event::listen(
        CommentReported::class,
        static function (CommentReported $event) use (&$reported): void {
            $reported[] = $event;
        },
    );

    DB::beginTransaction();
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Committed event source'),
        $author,
    );
    app(SetCommentReactionAction::class)->execute(
        $comment,
        'like',
        true,
        $author,
    );
    app(ReportCommentAction::class)->execute(
        $comment,
        new ReportCommentData('event-test'),
        $reporter,
    );

    expect($changed)->toBe([])
        ->and($reactions)->toBe([])
        ->and($reported)->toBe([]);

    DB::commit();

    expect($changed)->toHaveCount(1)
        ->and($changed[0]->schemaVersion)->toBe(1)
        ->and($changed[0]->commentId)->toBe($comment->id)
        ->and($reactions)->toHaveCount(1)
        ->and($reactions[0]->schemaVersion)->toBe(1)
        ->and($reported)->toHaveCount(1)
        ->and($reported[0]->schemaVersion)->toBe(1);

    DB::beginTransaction();
    $rolledBack = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Rolled-back event source'),
        $author,
    );
    app(SetCommentReactionAction::class)->execute(
        $rolledBack,
        'like',
        true,
        $author,
    );
    app(ReportCommentAction::class)->execute(
        $rolledBack,
        new ReportCommentData('rollback-event-test'),
        $reporter,
    );
    DB::rollBack();

    expect($changed)->toHaveCount(1)
        ->and($reactions)->toHaveCount(1)
        ->and($reported)->toHaveCount(1)
        ->and(Comment::query()->whereKey($rolledBack->id)->exists())->toBeFalse();
});

it('rolls anonymization back when a model observer vetoes the required soft delete', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Delete-veto article']);
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Identity must survive a failed anonymization'),
        new CommentActorData('member', 'veto-author'),
    );
    Event::listen(
        'eloquent.deleting: '.Comment::class,
        static fn (Comment $deleting): bool => $deleting->id !== $comment->id,
    );

    expect(fn () => app(AnonymizeCommentAction::class)->execute(
        $comment,
        new AnonymizeCommentData(
            $comment->revision,
            'Observer veto exercise',
        ),
        CommentActorData::system(),
    ))->toThrow(
        InvalidCommentLifecycleException::class,
        'could not be deleted',
    );

    $unchanged = Comment::query()->findOrFail($comment->id);

    expect($unchanged->body)
        ->toBe('Identity must survive a failed anonymization')
        ->and($unchanged->actor_id)->toBe('veto-author')
        ->and($unchanged->anonymized_at)->toBeNull()
        ->and($unchanged->revision)->toBe(1);
});

it('rolls anonymization back when a model observer vetoes the identity scrub', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Scrub-veto article']);
    $actor = new CommentActorData('member', 'scrub-veto-author');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Identity must survive a failed scrub'),
        $actor,
    );
    Comment::saving(static function (Comment $candidate): bool {
        return $candidate->anonymized_at === null;
    });
    Event::fake([CommentChanged::class]);

    expect(fn () => app(AnonymizeCommentAction::class)->execute(
        $comment,
        new AnonymizeCommentData(
            $comment->revision,
            'Observer scrub veto exercise',
        ),
        CommentActorData::system(),
    ))->toThrow(
        InvalidCommentLifecycleException::class,
        'could not be anonymized',
    );

    $unchanged = Comment::query()->withTrashed()->findOrFail($comment->id);

    expect($unchanged->body)->toBe('Identity must survive a failed scrub')
        ->and($unchanged->actor_type)->toBe($actor->type)
        ->and($unchanged->actor_id)->toBe($actor->id)
        ->and($unchanged->anonymized_at)->toBeNull()
        ->and($unchanged->trashed())->toBeFalse();
    Event::assertNotDispatched(CommentChanged::class);
});

it('rolls deletion audit state back when a model observer vetoes soft deletion', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Deletion-veto article']);
    $actor = new CommentActorData('member', 'delete-veto-author');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Deletion must be atomic'),
        $actor,
    );
    Event::listen(
        'eloquent.deleting: '.Comment::class,
        static fn (Comment $deleting): bool => $deleting->id !== $comment->id,
    );

    expect(fn () => app(DeleteCommentAction::class)->execute(
        $comment,
        new DeleteCommentData($comment->revision),
        $actor,
    ))->toThrow(
        InvalidCommentLifecycleException::class,
        'could not be deleted',
    );

    $unchanged = Comment::query()->findOrFail($comment->id);

    expect($unchanged->revision)->toBe(1)
        ->and($unchanged->deleted_by_type)->toBeNull()
        ->and($unchanged->deleted_by)->toBeNull()
        ->and($unchanged->trashed())->toBeFalse();
});

it('rolls deletion back when a model observer vetoes the parent counter update', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Delete counter-veto article']);
    $actor = new CommentActorData('member', 'delete-counter-veto-author');
    $create = app(CreateCommentAction::class);
    $parent = $create->execute($target, new CreateCommentData('Parent'), $actor);
    $reply = $create->execute(
        $target,
        new CreateCommentData('Reply', parentId: $parent->id),
        $actor,
    );
    Event::listen(
        'eloquent.updating: '.Comment::class,
        static fn (Comment $updating): bool => $updating->id !== $parent->id,
    );
    Event::fake([CommentChanged::class]);

    expect(fn () => app(DeleteCommentAction::class)->execute(
        $reply,
        new DeleteCommentData($reply->revision),
        $actor,
    ))->toThrow(
        InvalidCommentLifecycleException::class,
        'parent comment reply counter could not be updated',
    );

    expect(Comment::query()->findOrFail($reply->id)->revision)->toBe(1)
        ->and($parent->refresh()->reply_count)->toBe(1);
    Event::assertNotDispatched(CommentChanged::class);
});

it('rolls restoration back when a model observer vetoes the parent counter update', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Restore counter-veto article']);
    $actor = new CommentActorData('member', 'restore-counter-veto-author');
    $create = app(CreateCommentAction::class);
    $parent = $create->execute($target, new CreateCommentData('Parent'), $actor);
    $reply = $create->execute(
        $target,
        new CreateCommentData('Reply', parentId: $parent->id),
        $actor,
    );
    app(DeleteCommentAction::class)->execute(
        $reply,
        new DeleteCommentData($reply->revision),
        $actor,
    );
    $deletedReply = Comment::query()->withTrashed()->findOrFail($reply->id);
    Event::listen(
        'eloquent.updating: '.Comment::class,
        static fn (Comment $updating): bool => $updating->id !== $parent->id,
    );
    Event::fake([CommentChanged::class]);

    expect(fn () => app(RestoreCommentAction::class)->execute(
        $deletedReply,
        new RestoreCommentData($deletedReply->revision),
        $actor,
    ))->toThrow(
        InvalidCommentLifecycleException::class,
        'parent comment reply counter could not be updated',
    );

    expect(Comment::query()->withTrashed()->findOrFail($reply->id)->trashed())
        ->toBeTrue()
        ->and($parent->refresh()->reply_count)->toBe(0);
    Event::assertNotDispatched(CommentChanged::class);
});

it('rolls reaction removal back when a model observer vetoes deletion', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Reaction-veto article']);
    $actor = new CommentActorData('member', 'reaction-veto-author');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Reaction removal must be atomic'),
        $actor,
    );
    $events = [];
    Event::listen(
        CommentReactionChanged::class,
        static function (CommentReactionChanged $event) use (&$events): void {
            $events[] = $event;
        },
    );
    $reactions = app(SetCommentReactionAction::class);
    $reaction = $reactions->execute($comment, 'like', true, $actor);
    Event::listen(
        'eloquent.deleting: '.CommentReaction::class,
        static fn (CommentReaction $deleting): bool => $deleting->id !== $reaction?->id,
    );

    expect(fn () => $reactions->execute(
        $comment,
        'like',
        false,
        $actor,
    ))->toThrow(
        InvalidCommentMutationException::class,
        'could not be removed',
    );

    expect($comment->refresh()->reaction_count)->toBe(1)
        ->and(CommentReaction::query()->whereKey($reaction?->id)->exists())->toBeTrue()
        ->and($events)->toHaveCount(1)
        ->and($events[0]->active)->toBeTrue();
});

it('rolls comment creation and reply creation back when model observers veto required writes', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Creation-veto article']);
    $actor = new CommentActorData('member', 'creation-veto-author');
    $root = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Existing root'),
        $actor,
    );
    $stage = 'comment';
    Event::listen(
        'eloquent.creating: '.Comment::class,
        static function (Comment $creating) use (&$stage): bool {
            return $stage !== 'comment'
                || $creating->body !== 'Vetoed root';
        },
    );
    Event::listen(
        'eloquent.updating: '.Comment::class,
        static function (Comment $updating) use (&$stage, $root): bool {
            return $stage !== 'parent'
                || $updating->id !== $root->id
                || ! $updating->isDirty('reply_count');
        },
    );
    Event::fake([CommentChanged::class]);

    expect(fn () => app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Vetoed root'),
        $actor,
    ))->toThrow(
        InvalidCommentMutationException::class,
        'could not be created',
    );

    $stage = 'parent';

    expect(fn () => app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Vetoed reply', parentId: $root->id),
        $actor,
    ))->toThrow(
        InvalidCommentMutationException::class,
        'reply counter could not be updated',
    );

    expect(Comment::query()->count())->toBe(1)
        ->and($root->refresh()->reply_count)->toBe(0);

    Event::assertNotDispatched(CommentChanged::class);
});

it('rolls comment updates back when revision or comment observers veto persistence', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Update-veto article']);
    $actor = new CommentActorData('member', 'update-veto-author');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Original update content'),
        $actor,
    );
    $stage = 'revision';
    Event::listen(
        'eloquent.creating: '.CommentRevision::class,
        static function () use (&$stage): bool {
            return $stage !== 'revision';
        },
    );
    Event::listen(
        'eloquent.updating: '.Comment::class,
        static function (Comment $updating) use (&$stage, $comment): bool {
            return $stage !== 'comment'
                || $updating->id !== $comment->id;
        },
    );
    Event::fake([CommentChanged::class]);

    expect(fn () => app(UpdateCommentAction::class)->execute(
        $comment,
        new UpdateCommentData('Revision-vetoed update', $comment->revision),
        $actor,
    ))->toThrow(
        InvalidCommentMutationException::class,
        'revision could not be saved',
    );

    $stage = 'comment';

    expect(fn () => app(UpdateCommentAction::class)->execute(
        $comment,
        new UpdateCommentData('Comment-vetoed update', $comment->revision),
        $actor,
    ))->toThrow(
        InvalidCommentMutationException::class,
        'comment update could not be saved',
    );

    $unchanged = Comment::query()->findOrFail($comment->id);

    expect($unchanged->body)->toBe('Original update content')
        ->and($unchanged->revision)->toBe(1)
        ->and($unchanged->revisions()->count())->toBe(0);

    Event::assertNotDispatched(CommentChanged::class);
});

it('rolls revision restoration back when snapshot or comment observers veto persistence', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Revision-restore-veto article']);
    $actor = new CommentActorData('member', 'revision-restore-veto-author');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Original revision content'),
        $actor,
    );
    $comment = app(UpdateCommentAction::class)->execute(
        $comment,
        new UpdateCommentData('Current revision content', $comment->revision),
        $actor,
    );
    $historicalRevision = $comment->revisions()->firstOrFail();
    $stage = 'snapshot';
    Event::listen(
        'eloquent.creating: '.CommentRevision::class,
        static function () use (&$stage): bool {
            return $stage !== 'snapshot';
        },
    );
    Event::listen(
        'eloquent.updating: '.Comment::class,
        static function (Comment $updating) use (&$stage, $comment): bool {
            return $stage !== 'comment'
                || $updating->id !== $comment->id;
        },
    );
    Event::fake([CommentChanged::class]);

    expect(fn () => app(RestoreCommentRevisionAction::class)->execute(
        $comment,
        $historicalRevision,
        new RestoreCommentRevisionData($comment->revision),
        $actor,
    ))->toThrow(
        InvalidCommentLifecycleException::class,
        'revision could not be preserved',
    );

    $stage = 'comment';

    expect(fn () => app(RestoreCommentRevisionAction::class)->execute(
        $comment,
        $historicalRevision,
        new RestoreCommentRevisionData($comment->revision),
        $actor,
    ))->toThrow(
        InvalidCommentLifecycleException::class,
        'revision could not be restored',
    );

    $unchanged = Comment::query()->findOrFail($comment->id);

    expect($unchanged->body)->toBe('Current revision content')
        ->and($unchanged->revision)->toBe(2)
        ->and($unchanged->revisions()->count())->toBe(1);

    Event::assertNotDispatched(CommentChanged::class);
});

it('rolls moderation back when a comment observer vetoes persistence', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Moderation-veto article']);
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Moderation must be atomic'),
        new CommentActorData('member', 'moderation-veto-author'),
    );
    Event::listen(
        'eloquent.updating: '.Comment::class,
        static fn (Comment $updating): bool => $updating->id !== $comment->id,
    );
    Event::fake([CommentChanged::class]);

    expect(fn () => app(ModerateCommentAction::class)->execute(
        $comment,
        new ModerateCommentData(
            CommentStatus::Hidden,
            $comment->revision,
            reason: 'Observer veto exercise',
        ),
        CommentActorData::system(),
    ))->toThrow(
        InvalidCommentMutationException::class,
        'moderation could not be saved',
    );

    $unchanged = Comment::query()->findOrFail($comment->id);

    expect($unchanged->status)->toBe(CommentStatus::Approved)
        ->and($unchanged->revision)->toBe(1)
        ->and($unchanged->moderated_at)->toBeNull();

    Event::assertNotDispatched(CommentChanged::class);
});

it('rolls reports back when report or counter observers veto persistence', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Report-veto article']);
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Reporting must be atomic'),
        new CommentActorData('member', 'report-veto-author'),
    );
    $reporter = new CommentActorData('member', 'report-veto-reporter');
    $stage = 'report';
    Event::listen(
        'eloquent.saving: '.CommentReport::class,
        static function () use (&$stage): bool {
            return $stage !== 'report';
        },
    );
    Event::listen(
        'eloquent.updating: '.Comment::class,
        static function (Comment $updating) use (&$stage, $comment): bool {
            return $stage !== 'counter'
                || $updating->id !== $comment->id;
        },
    );
    Event::fake([CommentReported::class]);

    expect(fn () => app(ReportCommentAction::class)->execute(
        $comment,
        new ReportCommentData('observer-veto'),
        $reporter,
    ))->toThrow(
        InvalidCommentMutationException::class,
        'report could not be saved',
    );

    $stage = 'counter';

    expect(fn () => app(ReportCommentAction::class)->execute(
        $comment,
        new ReportCommentData('observer-veto'),
        $reporter,
    ))->toThrow(
        InvalidCommentMutationException::class,
        'report counters could not be updated',
    );

    $unchanged = Comment::query()->findOrFail($comment->id);

    expect(CommentReport::query()->count())->toBe(0)
        ->and($unchanged->report_count)->toBe(0)
        ->and($unchanged->open_report_count)->toBe(0);

    Event::assertNotDispatched(CommentReported::class);
});

it('rolls report review back when report or comment observers veto persistence', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Report-review-veto article']);
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Report review must be atomic'),
        new CommentActorData('member', 'report-review-veto-author'),
    );
    $report = app(ReportCommentAction::class)->execute(
        $comment,
        new ReportCommentData('observer-veto'),
        new CommentActorData('member', 'report-review-veto-reporter'),
    );
    $stage = 'report';
    Event::listen(
        'eloquent.updating: '.CommentReport::class,
        static function (CommentReport $updating) use (&$stage, $report): bool {
            return $stage !== 'report'
                || $updating->id !== $report->id;
        },
    );
    Event::listen(
        'eloquent.updating: '.Comment::class,
        static function (Comment $updating) use (&$stage, $comment): bool {
            return $stage !== 'comment'
                || $updating->id !== $comment->id;
        },
    );
    Event::fake([CommentChanged::class]);
    $review = new ResolveCommentReportData(
        CommentReportStatus::Resolved,
        $comment->refresh()->revision,
        'Observer veto exercise',
    );

    expect(fn () => app(ResolveCommentReportAction::class)->execute(
        $report,
        $review,
        CommentActorData::system(),
    ))->toThrow(
        InvalidCommentMutationException::class,
        'report review could not be saved',
    );

    $stage = 'comment';

    expect(fn () => app(ResolveCommentReportAction::class)->execute(
        $report,
        $review,
        CommentActorData::system(),
    ))->toThrow(
        InvalidCommentMutationException::class,
        'review counters could not be updated',
    );

    $unchangedReport = CommentReport::query()->findOrFail($report->id);
    $unchangedComment = Comment::query()->findOrFail($comment->id);

    expect($unchangedReport->status)->toBe(CommentReportStatus::Open)
        ->and($unchangedReport->resolution)->toBeNull()
        ->and($unchangedComment->open_report_count)->toBe(1)
        ->and($unchangedComment->revision)->toBe(1);

    Event::assertNotDispatched(CommentChanged::class);
});

it('rolls reaction creation back when reaction or counter observers veto persistence', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Reaction-create-veto article']);
    $actor = new CommentActorData('member', 'reaction-create-veto-author');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Reaction creation must be atomic'),
        $actor,
    );
    $stage = 'reaction';
    Event::listen(
        'eloquent.creating: '.CommentReaction::class,
        static function () use (&$stage): bool {
            return $stage !== 'reaction';
        },
    );
    Event::listen(
        'eloquent.updating: '.Comment::class,
        static function (Comment $updating) use (&$stage, $comment): bool {
            return $stage !== 'counter'
                || $updating->id !== $comment->id
                || ! $updating->isDirty('reaction_count');
        },
    );
    Event::fake([CommentReactionChanged::class]);

    expect(fn () => app(SetCommentReactionAction::class)->execute(
        $comment,
        'like',
        true,
        $actor,
    ))->toThrow(
        InvalidCommentMutationException::class,
        'reaction could not be created',
    );

    $stage = 'counter';

    expect(fn () => app(SetCommentReactionAction::class)->execute(
        $comment,
        'like',
        true,
        $actor,
    ))->toThrow(
        InvalidCommentMutationException::class,
        'reaction counter could not be updated',
    );

    expect(CommentReaction::query()->count())->toBe(0)
        ->and($comment->refresh()->reaction_count)->toBe(0);

    Event::assertNotDispatched(CommentReactionChanged::class);
});

it('replays an exact idempotent reply without duplicating rows counters or events', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $actor = new CommentActorData('member', '42');
    $create = app(CreateCommentAction::class);
    $root = $create->execute(
        $target,
        new CreateCommentData('Root'),
        $actor,
    );
    $data = new CreateCommentData(
        body: 'Only one reply',
        parentId: $root->id,
        tags: ['release'],
        metadata: ['source' => 'member-api'],
        idempotencyKey: Str::uuid()->toString(),
    );
    Event::fake([CommentChanged::class]);

    $first = $create->execute($target, $data, $actor);
    $replayed = $create->execute($target, $data, $actor);

    expect($first->wasRecentlyCreated)->toBeTrue()
        ->and($replayed->wasRecentlyCreated)->toBeFalse()
        ->and($replayed->id)->toBe($first->id)
        ->and($replayed->revision)->toBe(1)
        ->and(Comment::query()->count())->toBe(2)
        ->and($root->refresh()->reply_count)->toBe(1);

    Event::assertDispatchedTimes(CommentChanged::class, 1);
    Event::assertDispatched(
        CommentChanged::class,
        static fn (CommentChanged $event): bool => $event->commentId === $first->id
            && $event->operation === CommentChangeOperation::Created
            && $event->revision === 1
            && $event->schemaVersion === 1
            && $event->actor->type === 'member'
            && $event->actor->id === '42',
    );
});

it('replays an exact idempotency key before applying tightened content policy', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Policy evolution article']);
    $actor = new CommentActorData('member', 'policy-evolution-author');
    $key = Str::uuid()->toString();
    $data = new CreateCommentData(
        body: 'Previously accepted content',
        tags: ['previously-accepted'],
        idempotencyKey: $key,
    );
    $create = app(CreateCommentAction::class);
    Event::fake([CommentChanged::class]);
    $created = $create->execute($target, $data, $actor);
    config()->set([
        'comments.content.maximum_bytes' => 1,
        'comments.content.allowed_formats' => [CommentFormat::Markdown->value],
        'comments.content.maximum_tags' => 1,
    ]);

    $replayed = $create->execute($target, $data, $actor);

    expect($replayed->id)->toBe($created->id)
        ->and($replayed->wasRecentlyCreated)->toBeFalse()
        ->and(Comment::query()->count())->toBe(1);
    expect(fn () => $create->execute(
        $target,
        new CreateCommentData(
            body: 'A different invalid payload',
            idempotencyKey: $key,
        ),
        $actor,
    ))->toThrow(CommentIdempotencyConflictException::class);
    Event::assertDispatchedTimes(CommentChanged::class, 1);
});

it('rejects a conflicting idempotency replay with the stable 409 domain response', function (): void {
    $firstTarget = TestCommentTarget::query()->create(['name' => 'First article']);
    $secondTarget = TestCommentTarget::query()->create(['name' => 'Second article']);
    $actor = new CommentActorData('member', '42');
    $key = Str::uuid()->toString();
    $data = new CreateCommentData(
        body: 'Canonical payload',
        idempotencyKey: $key,
    );
    $create = app(CreateCommentAction::class);
    Event::fake([CommentChanged::class]);

    $created = $create->execute($firstTarget, $data, $actor);

    expect(fn () => $create->execute($secondTarget, $data, $actor))
        ->toThrow(CommentIdempotencyConflictException::class);

    $response = CommentIdempotencyConflictException::forKey()->render(
        Request::create('/comments', 'POST'),
    );

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true))->toMatchArray([
            'code' => 'comment_idempotency_conflict',
        ])
        ->and(Comment::query()->count())->toBe(1)
        ->and(Comment::query()->firstOrFail()->id)->toBe($created->id)
        ->and(Comment::query()
            ->where('commentable_id', (string) $secondTarget->getKey())
            ->exists())->toBeFalse();

    Event::assertDispatchedTimes(CommentChanged::class, 1);
});

it('replays a soft deleted idempotent comment as the same tombstone', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $actor = new CommentActorData('member', '42');
    $data = new CreateCommentData(
        body: 'Delete once',
        idempotencyKey: Str::uuid()->toString(),
    );
    $create = app(CreateCommentAction::class);
    Event::fake([CommentChanged::class]);

    $created = $create->execute($target, $data, $actor);
    app(DeleteCommentAction::class)->execute(
        $created,
        new DeleteCommentData($created->revision),
        $actor,
    );
    $replayed = $create->execute($target, $data, $actor);
    $payload = app(CommentProjectionFactory::class)
        ->publicComment($replayed, $target)
        ->toArray();

    expect($replayed->id)->toBe($created->id)
        ->and($replayed->wasRecentlyCreated)->toBeFalse()
        ->and($replayed->trashed())->toBeTrue()
        ->and($replayed->revision)->toBe(2)
        ->and(Comment::query()->count())->toBe(0)
        ->and(Comment::query()->withTrashed()->count())->toBe(1)
        ->and($payload)->not->toHaveKeys([
            'body',
            'format',
            'locale',
            'tags',
            'author',
            'reactions',
            'reactionCount',
            'attachmentCount',
        ]);

    Event::assertDispatchedTimes(CommentChanged::class, 2);
    Event::assertDispatched(
        CommentChanged::class,
        static fn (CommentChanged $event): bool => $event->operation
            === CommentChangeOperation::Deleted,
    );
});

it('does not dispatch rolled back events and allows the idempotent request to retry', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $actor = new CommentActorData('member', '42');
    $data = new CreateCommentData(
        body: 'Retry after rollback',
        idempotencyKey: Str::uuid()->toString(),
    );
    $create = app(CreateCommentAction::class);
    Event::fake([CommentChanged::class]);

    expect(fn () => DB::transaction(function () use ($actor, $create, $data, $target): void {
        $create->execute($target, $data, $actor);

        Event::assertNotDispatched(CommentChanged::class);

        throw new RuntimeException('Force the outer transaction to roll back.');
    }))->toThrow(RuntimeException::class, 'Force the outer transaction to roll back.');

    expect(Comment::query()->withTrashed()->count())->toBe(0);
    Event::assertNotDispatched(CommentChanged::class);

    $retried = $create->execute($target, $data, $actor);

    expect($retried->wasRecentlyCreated)->toBeTrue()
        ->and(Comment::query()->count())->toBe(1);
    Event::assertDispatchedTimes(CommentChanged::class, 1);
});

it('retains mutation locks across savepoint rollback and releases them after root rollback', function (): void {
    $commentId = 'rollback-lock-compatibility-'.Str::uuid()->toString();
    $lockKey = 'comments:mutation:'.hash('sha256', $commentId);
    $contender = Cache::store('file')->lock($lockKey, 5, 'independent-contender');
    $connectionName = 'comments_rollback_compatibility';
    config()->set("database.connections.{$connectionName}", [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('comments.connection', $connectionName);
    $connection = DB::connection($connectionName);

    try {
        $connection->beginTransaction();
        $connection->beginTransaction();

        app(CommentMutationLock::class)->execute($commentId, static fn (): null => null);

        $connection->rollBack();

        expect($connection->transactionLevel())->toBe(1)
            ->and($contender->get())->toBeFalse();

        $connection->rollBack();

        expect($connection->transactionLevel())->toBe(0)
            ->and($contender->get())->toBeTrue();
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $contender->release();
        DB::purge($connectionName);
    }
});

it('rejects stale and no-op mutations without changing revision history or emitting events', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $actor = new CommentActorData('member', '42');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Unchanged'),
        $actor,
    );
    Event::fake([CommentChanged::class]);

    expect(fn () => app(UpdateCommentAction::class)->execute(
        $comment,
        new UpdateCommentData('Stale update', $comment->revision + 1),
        $actor,
    ))->toThrow(StaleCommentException::class);

    expect(fn () => app(ModerateCommentAction::class)->execute(
        $comment,
        new ModerateCommentData(
            CommentStatus::Hidden,
            $comment->revision + 1,
            'Stale moderation',
        ),
        CommentActorData::system(),
    ))->toThrow(StaleCommentException::class);

    $unchanged = app(UpdateCommentAction::class)->execute(
        $comment,
        new UpdateCommentData($comment->body, $comment->revision),
        $actor,
    );

    expect($unchanged->revision)->toBe(1)
        ->and($unchanged->body)->toBe('Unchanged')
        ->and($unchanged->revisions()->count())->toBe(0);
    Event::assertNotDispatched(CommentChanged::class);
});

it('restores historical content by snapshotting the current content as a new revision', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $actor = new CommentActorData('member', '42');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData(
            body: 'Original body',
            format: CommentFormat::Markdown,
            locale: 'en',
            tags: ['original'],
            metadata: ['version' => 'original'],
        ),
        $actor,
    );
    Event::fake([CommentChanged::class]);

    $updated = app(UpdateCommentAction::class)->execute(
        $comment,
        new UpdateCommentData(
            body: 'Current body',
            expectedRevision: $comment->revision,
            format: CommentFormat::Plain,
            locale: null,
            tags: ['current'],
            metadata: ['version' => 'current'],
        ),
        $actor,
    );
    $historical = CommentRevision::query()
        ->where('comment_id', $comment->id)
        ->where('revision', 1)
        ->firstOrFail();
    $restored = app(RestoreCommentRevisionAction::class)->execute(
        $updated,
        $historical,
        new RestoreCommentRevisionData($updated->revision),
        $actor,
    );
    $currentSnapshot = CommentRevision::query()
        ->where('comment_id', $comment->id)
        ->where('revision', 2)
        ->firstOrFail();

    expect($restored->revision)->toBe(3)
        ->and($restored->body)->toBe('Original body')
        ->and($restored->format)->toBe(CommentFormat::Markdown)
        ->and($restored->locale)->toBe('en')
        ->and($restored->tags)->toBe(['original'])
        ->and($restored->metadata)->toBe(['version' => 'original'])
        ->and($restored->revisions()->count())->toBe(2)
        ->and($currentSnapshot->body)->toBe('Current body')
        ->and($currentSnapshot->format)->toBe(CommentFormat::Plain)
        ->and($currentSnapshot->tags)->toBe(['current'])
        ->and($currentSnapshot->metadata)->toBe(['version' => 'current']);

    expect(fn () => app(RestoreCommentRevisionAction::class)->execute(
        $restored,
        $historical,
        new RestoreCommentRevisionData(2),
        $actor,
    ))->toThrow(StaleCommentException::class);

    Event::assertDispatchedTimes(CommentChanged::class, 2);
    Event::assertDispatched(
        CommentChanged::class,
        static fn (CommentChanged $event): bool => $event->operation
            === CommentChangeOperation::RevisionRestored
            && $event->revision === 3,
    );
});

it('deletes and restores a reply with exact parent counter and revision semantics', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $actor = new CommentActorData('member', '42');
    $create = app(CreateCommentAction::class);
    $root = $create->execute($target, new CreateCommentData('Root'), $actor);
    $reply = $create->execute(
        $target,
        new CreateCommentData('Reply', parentId: $root->id),
        $actor,
    );
    Event::fake([CommentChanged::class]);

    expect(fn () => app(DeleteCommentAction::class)->execute(
        $reply,
        new DeleteCommentData(2),
        $actor,
    ))->toThrow(StaleCommentException::class);

    expect($root->refresh()->reply_count)->toBe(1);
    Event::assertNotDispatched(CommentChanged::class);

    $deleted = app(DeleteCommentAction::class)->execute(
        $reply,
        new DeleteCommentData(1),
        $actor,
    );
    $trashed = Comment::query()->withTrashed()->findOrFail($reply->id);

    expect($deleted)->toBeTrue()
        ->and($trashed->trashed())->toBeTrue()
        ->and($trashed->revision)->toBe(2)
        ->and($trashed->deleted_by_type)->toBe('member')
        ->and($trashed->deleted_by)->toBe('42')
        ->and($root->refresh()->reply_count)->toBe(0);

    expect(fn () => app(RestoreCommentAction::class)->execute(
        $trashed,
        new RestoreCommentData(1),
        $actor,
    ))->toThrow(StaleCommentException::class);

    expect($root->refresh()->reply_count)->toBe(0);

    $restored = app(RestoreCommentAction::class)->execute(
        $trashed,
        new RestoreCommentData(2),
        $actor,
    );

    expect($restored->trashed())->toBeFalse()
        ->and($restored->revision)->toBe(3)
        ->and($restored->status)->toBe(CommentStatus::Pending)
        ->and($restored->restored_at)->not->toBeNull()
        ->and($restored->restored_by_type)->toBe('member')
        ->and($restored->restored_by)->toBe('42')
        ->and($root->refresh()->reply_count)->toBe(1);

    expect(fn () => app(RestoreCommentAction::class)->execute(
        $restored,
        new RestoreCommentData(3),
        CommentActorData::system(),
    ))->toThrow(InvalidCommentLifecycleException::class);

    expect($root->refresh()->reply_count)->toBe(1);
    Event::assertDispatchedTimes(CommentChanged::class, 2);
    Event::assertDispatched(
        CommentChanged::class,
        static fn (CommentChanged $event): bool => $event->operation
            === CommentChangeOperation::Restored
            && $event->revision === 3,
    );
});

it('rebuilds queryable metadata when an ordinary soft deletion is restored', function (): void {
    config()->set([
        'comments.metadata.schemas' => [TestCommentMetadataSchema::class],
        'comments.metadata.digest_key' => 'restore-metadata-test-key',
    ]);
    app()->forgetInstance(CommentMetadataRegistry::class);
    $target = TestCommentTarget::query()->create(['name' => 'Restore metadata']);
    $actor = new CommentActorData('member', 'restore-metadata-author');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Restorable metadata', metadata: [
            'workflow_event' => 'restorable',
        ]),
        $actor,
        CommentAudience::Member,
    );
    app(DeleteCommentAction::class)->execute(
        $comment,
        new DeleteCommentData(expectedRevision: 1),
        $actor,
        CommentAudience::Member,
    );

    expect(CommentMetadataValue::query()->where('comment_id', $comment->id)->exists())
        ->toBeFalse();

    $restored = app(RestoreCommentAction::class)->execute(
        $comment->refresh(),
        new RestoreCommentData(expectedRevision: 2),
        $actor,
        CommentAudience::Member,
    );

    expect(CommentMetadataValue::query()->where('comment_id', $comment->id)->count())->toBe(1)
        ->and(app(FindLatestTargetCommentAction::class)->execute(
            $target,
            $actor,
            new CommentSelectorData(metadataEquals: ['workflow.event' => 'restorable']),
            CommentAudience::Member,
        )?->id)->toBe($restored->id);
});

it('rejects restoring a reply while its parent is deleted', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $actor = new CommentActorData('member', '42');
    $create = app(CreateCommentAction::class);
    $root = $create->execute($target, new CreateCommentData('Root'), $actor);
    $reply = $create->execute(
        $target,
        new CreateCommentData('Reply', parentId: $root->id),
        $actor,
    );
    $delete = app(DeleteCommentAction::class);
    $delete->execute($reply, new DeleteCommentData(1), $actor);
    $delete->execute($root, new DeleteCommentData(1), $actor);
    Event::fake([CommentChanged::class]);

    expect(fn () => app(RestoreCommentAction::class)->execute(
        $reply,
        new RestoreCommentData(2),
        $actor,
    ))->toThrow(
        InvalidCommentLifecycleException::class,
        'active, non-anonymized parent',
    );

    expect(Comment::query()->withTrashed()->findOrFail($reply->id)->trashed())->toBeTrue()
        ->and(Comment::query()->withTrashed()->findOrFail($root->id)->reply_count)->toBe(0);
    Event::assertNotDispatched(CommentChanged::class);
});

it('anonymizes terminally scrubs identifying state and replays only the same tombstone', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $author = new CommentActorData('member', '42');
    $system = CommentActorData::system();
    $data = new CreateCommentData(
        body: 'Original identifying body',
        format: CommentFormat::Markdown,
        locale: 'en',
        tags: ['identity'],
        metadata: ['email' => 'person@example.test'],
        idempotencyKey: Str::uuid()->toString(),
    );
    $create = app(CreateCommentAction::class);
    Event::fake([CommentChanged::class]);

    $comment = $create->execute($target, $data, $author);
    $updated = app(UpdateCommentAction::class)->execute(
        $comment,
        new UpdateCommentData(
            body: 'Current identifying body',
            expectedRevision: 1,
            locale: 'bg',
            tags: ['current-identity'],
            metadata: ['phone' => '+359000000'],
        ),
        $author,
    );
    $report = CommentReport::query()->create([
        'comment_id' => $comment->id,
        'reporter_type' => 'member',
        'reporter_id' => '84',
        'reason' => 'abuse',
        'details' => 'Identifying reporter details',
        'status' => CommentReportStatus::Resolved,
        'reviewed_by_type' => 'moderator',
        'reviewed_by' => '7',
        'resolution' => 'Identifying resolution text',
        'reviewed_at' => now(),
    ]);
    CommentReport::query()->create([
        'comment_id' => $comment->id,
        'reporter_type' => $author->type,
        'reporter_id' => $author->id,
        'reason' => 'self-report',
        'status' => CommentReportStatus::Open,
    ]);
    CommentReaction::query()->create([
        'comment_id' => $comment->id,
        'actor_type' => $author->type,
        'actor_id' => $author->id,
        'type' => 'like',
    ]);
    CommentReaction::query()->create([
        'comment_id' => $comment->id,
        'actor_type' => 'member',
        'actor_id' => '84',
        'type' => 'helpful',
    ]);
    $updated->forceFill([
        'reaction_count' => 2,
        'report_count' => 2,
        'open_report_count' => 1,
    ])->save();
    $media = Media::factory()->create([
        'filename' => 'anonymization-attachment.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'type' => MediaType::DOCUMENT,
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => $author->type,
        'uploaded_by' => $author->id,
    ]);
    $attachment = app(AttachCommentMediaAction::class)->execute(
        $updated,
        $media,
        $author,
    );
    $moderated = app(ModerateCommentAction::class)->execute(
        $updated,
        new ModerateCommentData(
            CommentStatus::Hidden,
            $updated->revision,
            'Identifying moderation reason',
        ),
        $system,
    );
    $anonymize = app(AnonymizeCommentAction::class);

    expect(fn () => $anonymize->execute(
        $moderated,
        new AnonymizeCommentData(2, 'Right to erasure'),
        $system,
    ))->toThrow(StaleCommentException::class);

    $anonymization = new AnonymizeCommentData(
        $moderated->revision,
        'Right to erasure',
    );
    $anonymized = $anonymize->execute($moderated, $anonymization, $system);
    $repeated = $anonymize->execute($anonymized, $anonymization, $system);
    $replayed = $create->execute($target, $data, $author);
    $scrubbedReport = $report->refresh();

    expect($anonymized->trashed())->toBeTrue()
        ->and($anonymized->revision)->toBe(4)
        ->and($anonymized->actor_type)->toBeNull()
        ->and($anonymized->actor_id)->toBeNull()
        ->and($anonymized->body)->toBe('')
        ->and($anonymized->format)->toBe(CommentFormat::Plain)
        ->and($anonymized->locale)->toBeNull()
        ->and($anonymized->tags)->toBeNull()
        ->and($anonymized->metadata)->toBeNull()
        ->and($anonymized->moderated_by_type)->toBeNull()
        ->and($anonymized->moderated_by)->toBeNull()
        ->and($anonymized->moderation_reason)->toBeNull()
        ->and($anonymized->moderated_at)->toBeNull()
        ->and($anonymized->anonymized_at)->not->toBeNull()
        ->and($anonymized->anonymized_by_type)->toBe('system')
        ->and($anonymized->anonymization_reason)->toBe('Right to erasure')
        ->and($anonymized->deleted_by_type)->toBe('system')
        ->and($anonymized->status)->toBe(CommentStatus::Hidden)
        ->and($anonymized->visibility)->toBe(CommentVisibility::Public)
        ->and($anonymized->reaction_count)->toBe(1)
        ->and($anonymized->report_count)->toBe(1)
        ->and($anonymized->open_report_count)->toBe(0)
        ->and(CommentRevision::query()->where('comment_id', $comment->id)->count())->toBe(0)
        ->and(CommentReaction::query()
            ->where('comment_id', $comment->id)
            ->where('actor_type', $author->type)
            ->where('actor_id', $author->id)
            ->exists())->toBeFalse()
        ->and(CommentReaction::query()
            ->where('comment_id', $comment->id)
            ->where('actor_id', '84')
            ->exists())->toBeTrue()
        ->and(CommentReport::query()
            ->where('comment_id', $comment->id)
            ->where('reporter_type', $author->type)
            ->where('reporter_id', $author->id)
            ->exists())->toBeFalse()
        ->and(MediaAssociation::query()->whereKey($attachment->id)->exists())->toBeFalse()
        ->and(Media::query()->whereKey($media->id)->exists())->toBeTrue()
        ->and($scrubbedReport->reporter_type)->toBe('member')
        ->and($scrubbedReport->reporter_id)->toBe('84')
        ->and($scrubbedReport->reason)->toBe('abuse')
        ->and($scrubbedReport->status)->toBe(CommentReportStatus::Resolved)
        ->and($scrubbedReport->details)->toBeNull()
        ->and($scrubbedReport->resolution)->toBeNull()
        ->and($repeated->id)->toBe($anonymized->id)
        ->and($repeated->revision)->toBe(4)
        ->and($replayed->id)->toBe($anonymized->id)
        ->and($replayed->wasRecentlyCreated)->toBeFalse()
        ->and($replayed->trashed())->toBeTrue()
        ->and($replayed->anonymized_at)->not->toBeNull()
        ->and(Comment::query()->count())->toBe(0)
        ->and(Comment::query()->withTrashed()->count())->toBe(1);

    expect(fn () => app(RestoreCommentAction::class)->execute(
        $anonymized,
        new RestoreCommentData($anonymized->revision),
        $system,
    ))->toThrow(
        InvalidCommentLifecycleException::class,
        'cannot be restored',
    );
    expect(fn () => app(ModerateCommentAction::class)->execute(
        $anonymized,
        new ModerateCommentData(
            CommentStatus::Approved,
            $anonymized->revision,
            'Must remain terminal',
        ),
        $system,
    ))->toThrow(
        InvalidCommentLifecycleException::class,
        'cannot be moderated',
    );

    expect(is_a(
        CommentChanged::class,
        ShouldDispatchAfterCommit::class,
        true,
    ))->toBeTrue();
    Event::assertDispatchedTimes(CommentChanged::class, 4);
    Event::assertDispatched(
        CommentChanged::class,
        static fn (CommentChanged $event): bool => $event->operation
            === CommentChangeOperation::Anonymized
            && $event->revision === 4,
    );
});

it('detaches and anonymizes attachment associations after Media soft deletion', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Deleted Media lifecycle']);
    $author = new CommentActorData('member', 'deleted-media-owner');
    $system = CommentActorData::system();
    $create = app(CreateCommentAction::class);
    $detachedComment = $create->execute(
        $target,
        new CreateCommentData('Detach deleted Media'),
        $author,
    );
    $detachedMedia = Media::factory()->create([
        'filename' => 'detach-deleted-media.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'type' => MediaType::DOCUMENT,
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => $author->type,
        'uploaded_by' => $author->id,
    ]);
    $detachedAssociation = app(AttachCommentMediaAction::class)->execute(
        $detachedComment,
        $detachedMedia,
        $author,
    );
    $detachedMedia->delete();

    expect(app(DetachCommentMediaAction::class)->execute(
        $detachedComment,
        $detachedAssociation->id,
        $author,
        CommentAudience::Public,
    ))->toBeTrue()
        ->and(MediaAssociation::query()->whereKey($detachedAssociation->id)->exists())
        ->toBeFalse()
        ->and(Media::query()->withTrashed()->findOrFail($detachedMedia->id)->trashed())
        ->toBeTrue();

    $anonymizedComment = $create->execute(
        $target,
        new CreateCommentData('Erase deleted and inactive Media associations'),
        $author,
    );
    $deletedMedia = Media::factory()->create([
        'filename' => 'anonymize-deleted-media.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'type' => MediaType::DOCUMENT,
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => $author->type,
        'uploaded_by' => $author->id,
    ]);
    $activeAssociation = app(AttachCommentMediaAction::class)->execute(
        $anonymizedComment,
        $deletedMedia,
        $author,
    );
    $deletedMedia->delete();
    $inactiveMedia = Media::factory()->create();
    $inactiveAssociation = MediaAssociation::query()->create([
        'media_id' => $inactiveMedia->id,
        'associable_type' => $anonymizedComment->getMorphClass(),
        'associable_id' => $anonymizedComment->id,
        'collection' => 'attachments',
        'order' => 1,
        'is_active' => false,
    ]);

    $anonymized = app(AnonymizeCommentAction::class)->execute(
        $anonymizedComment,
        new AnonymizeCommentData(
            $anonymizedComment->revision,
            'Terminal privacy cleanup',
        ),
        $system,
        CommentAudience::Public,
    );

    expect($anonymized->trashed())->toBeTrue()
        ->and($anonymized->anonymized_at)->not->toBeNull()
        ->and(MediaAssociation::query()
            ->whereKey([$activeAssociation->id, $inactiveAssociation->id])
            ->exists())->toBeFalse()
        ->and(Media::query()->withTrashed()->findOrFail($deletedMedia->id)->trashed())
        ->toBeTrue()
        ->and(Media::query()->whereKey($inactiveMedia->id)->exists())->toBeTrue();
});
