<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Nvl\Comments\Actions\AnonymizeCommentAction;
use Nvl\Comments\Actions\CreateRichCommentAction;
use Nvl\Comments\Actions\RestoreCommentRevisionAction;
use Nvl\Comments\Actions\UpdateRichCommentAction;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentMentionChangeData;
use Nvl\Comments\Data\Mutations\AnonymizeCommentData;
use Nvl\Comments\Data\Mutations\CommentDocumentData;
use Nvl\Comments\Data\Mutations\CreateRichCommentData;
use Nvl\Comments\Data\Mutations\RestoreCommentRevisionData;
use Nvl\Comments\Data\Mutations\UpdateRichCommentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Events\CommentMentionsChanged;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Services\CommentMentionResourceRegistry;
use Nvl\Comments\Tests\Fixtures\TestCommentMentionResourceResolver;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;

/**
 * Register deterministic mention event resources.
 */
function commentsMentionEventRegister(): void
{
    config()->set('comments.mentions.enabled', true);
    app(CommentMentionResourceRegistry::class)->register(
        'organization',
        TestCommentMentionResourceResolver::class,
    );
}

/**
 * Build one document from ordered opaque resource identifiers.
 */
function commentsMentionEventDocument(string ...$ids): CommentDocumentData
{
    return new CommentDocumentData(1, [[
        'type' => 'paragraph',
        'children' => array_map(
            static fn (string $id): array => [
                'type' => 'mention',
                'tokenId' => (string) Str::uuid(),
                'resource' => 'organization',
                'id' => $id,
            ],
            $ids,
        ),
    ]]);
}

it('rejects malformed or oversized durable mention change facts', function (): void {
    $change = new CommentMentionChangeData(
        resourceAlias: 'organization',
        resourceId: 'org-1',
        tokenId: (string) Str::uuid(),
    );

    expect(fn () => new CommentMentionsChanged(
        commentId: (string) Str::uuid(),
        targetType: 'article',
        targetId: (string) Str::uuid(),
        revision: 1,
        added: array_fill(0, 101, $change),
        removed: [],
    ))->toThrow(InvalidArgumentException::class, 'exceed hard bounds')
        ->and(fn () => new CommentMentionsChanged(
            commentId: (string) Str::uuid(),
            targetType: 'article',
            targetId: (string) Str::uuid(),
            revision: 1,
            added: ['not-a-change-fact'],
            removed: [],
        ))->toThrow(InvalidArgumentException::class, 'change facts are invalid');
});

it('rejects malformed mention identities before they enter durable events', function (): void {
    expect(fn () => new CommentMentionChangeData(
        resourceAlias: 'organization',
        resourceId: 'org-1',
        tokenId: 'not-a-uuid',
    ))->toThrow(InvalidArgumentException::class, 'change facts are invalid');
});

it('emits bounded after-commit facts for newly added mention identities', function (): void {
    commentsMentionEventRegister();
    $target = TestCommentTarget::query()->create(['name' => 'Mention event create']);
    $actor = new CommentActorData('member', 'event-author');
    Event::fake([CommentMentionsChanged::class]);

    $comment = app(CreateRichCommentAction::class)->execute(
        $target,
        new CreateRichCommentData(commentsMentionEventDocument('org-1', 'org-1')),
        $actor,
        CommentAudience::Member,
    );

    expect(is_a(CommentMentionsChanged::class, ShouldDispatchAfterCommit::class, true))
        ->toBeTrue();
    Event::assertDispatchedTimes(CommentMentionsChanged::class, 1);
    Event::assertDispatched(
        CommentMentionsChanged::class,
        static fn (CommentMentionsChanged $event): bool => $event->commentId === $comment->id
            && $event->targetType === $comment->commentable_type
            && $event->targetId === $target->id
            && $event->revision === 1
            && count($event->added) === 1
            && $event->added[0]->resourceAlias === 'organization'
            && $event->added[0]->resourceId === 'org-1'
            && Str::isUuid($event->added[0]->tokenId)
            && $event->removed === [],
    );
});

it('diffs updates by alias and resource identity and ignores token reordering', function (): void {
    commentsMentionEventRegister();
    $target = TestCommentTarget::query()->create(['name' => 'Mention event update']);
    $actor = new CommentActorData('member', 'event-author');
    $comment = app(CreateRichCommentAction::class)->execute(
        $target,
        new CreateRichCommentData(commentsMentionEventDocument('org-1', 'org-2')),
        $actor,
        CommentAudience::Member,
    );
    Event::fake([CommentMentionsChanged::class]);
    $replacement = commentsMentionEventDocument('org-2', 'org-1');

    $reordered = app(UpdateRichCommentAction::class)->execute(
        $comment,
        new UpdateRichCommentData($replacement, expectedRevision: 1),
        $actor,
        CommentAudience::Member,
    );

    Event::assertNotDispatched(CommentMentionsChanged::class);

    $changed = app(UpdateRichCommentAction::class)->execute(
        $reordered,
        new UpdateRichCommentData(commentsMentionEventDocument('org-2'), expectedRevision: 2),
        $actor,
        CommentAudience::Member,
    );

    Event::assertDispatchedTimes(CommentMentionsChanged::class, 1);
    Event::assertDispatched(
        CommentMentionsChanged::class,
        static fn (CommentMentionsChanged $event): bool => $event->commentId === $changed->id
            && $event->revision === 3
            && $event->added === []
            && count($event->removed) === 1
            && $event->removed[0]->resourceId === 'org-1',
    );
});

it('does not duplicate mention facts for exact idempotent creation retries', function (): void {
    commentsMentionEventRegister();
    $target = TestCommentTarget::query()->create(['name' => 'Mention event retry']);
    $actor = new CommentActorData('member', 'event-author');
    $data = new CreateRichCommentData(
        commentsMentionEventDocument('org-1'),
        idempotencyKey: (string) Str::uuid(),
    );
    Event::fake([CommentMentionsChanged::class]);
    $action = app(CreateRichCommentAction::class);

    $action->execute($target, $data, $actor, CommentAudience::Member);
    $action->execute($target, $data, $actor, CommentAudience::Member);

    Event::assertDispatchedTimes(CommentMentionsChanged::class, 1);
});

it('discards mention facts when an outer transaction rolls back', function (): void {
    commentsMentionEventRegister();
    $target = TestCommentTarget::query()->create(['name' => 'Mention event rollback']);
    $actor = new CommentActorData('member', 'event-author');
    Event::fake([CommentMentionsChanged::class]);

    expect(fn () => DB::transaction(function () use ($actor, $target): void {
        app(CreateRichCommentAction::class)->execute(
            $target,
            new CreateRichCommentData(commentsMentionEventDocument('org-1')),
            $actor,
            CommentAudience::Member,
        );

        Event::assertNotDispatched(CommentMentionsChanged::class);

        throw new RuntimeException('Force rollback.');
    }))->toThrow(RuntimeException::class, 'Force rollback.');

    Event::assertNotDispatched(CommentMentionsChanged::class);
    expect(Comment::query()->where('commentable_id', $target->id)->exists())->toBeFalse();
});

it('emits mention identity diffs when restoring a historical rich revision', function (): void {
    commentsMentionEventRegister();
    $target = TestCommentTarget::query()->create(['name' => 'Mention restore event']);
    $actor = new CommentActorData('member', 'restore-event-author');
    $comment = app(CreateRichCommentAction::class)->execute(
        $target,
        new CreateRichCommentData(commentsMentionEventDocument('org-1')),
        $actor,
        CommentAudience::Member,
    );
    $updated = app(UpdateRichCommentAction::class)->execute(
        $comment,
        new UpdateRichCommentData(
            commentsMentionEventDocument('org-2'),
            expectedRevision: 1,
        ),
        $actor,
        CommentAudience::Member,
    );
    $historical = CommentRevision::query()
        ->where('comment_id', $comment->id)
        ->where('revision', 1)
        ->sole();
    Event::fake([CommentMentionsChanged::class]);

    app(RestoreCommentRevisionAction::class)->execute(
        $updated,
        $historical,
        new RestoreCommentRevisionData(expectedRevision: 2),
        $actor,
        CommentAudience::Member,
    );

    Event::assertDispatched(
        CommentMentionsChanged::class,
        static fn (CommentMentionsChanged $event): bool => $event->revision === 3
            && array_column($event->added, 'resourceId') === ['org-1']
            && array_column($event->removed, 'resourceId') === ['org-2'],
    );
});

it('emits removals when anonymization scrubs current mentions', function (): void {
    commentsMentionEventRegister();
    $target = TestCommentTarget::query()->create(['name' => 'Mention anonymize event']);
    $comment = app(CreateRichCommentAction::class)->execute(
        $target,
        new CreateRichCommentData(commentsMentionEventDocument('org-1')),
        new CommentActorData('member', 'anonymize-event-author'),
        CommentAudience::Member,
    );
    Event::fake([CommentMentionsChanged::class]);

    app(AnonymizeCommentAction::class)->execute(
        $comment,
        new AnonymizeCommentData(expectedRevision: 1, reason: 'Privacy request'),
        CommentActorData::system(),
        CommentAudience::Management,
    );

    Event::assertDispatched(
        CommentMentionsChanged::class,
        static fn (CommentMentionsChanged $event): bool => $event->revision === 2
            && $event->added === []
            && array_column($event->removed, 'resourceId') === ['org-1'],
    );
});
