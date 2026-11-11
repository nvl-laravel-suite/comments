<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Events\CommentReactionChanged;
use Nvl\Comments\Events\CommentReported;
use Nvl\Comments\Exceptions\CommentIdempotencyConflictException;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Exceptions\StaleCommentException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentActor;
use Nvl\Comments\Services\CommentAnonymizer;
use Nvl\Comments\Services\CommentStateReconciler;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\NativeModerationPolicy;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Support\Enums\PublicActor;
use Nvl\Support\Enums\SystemActor;

it('audits and repairs native counter drift without changing the comment body', function (): void {
    config(['nvl-comments.anonymous.enabled' => true]);
    $target = TestCommentTarget::create(['name' => 'Reconciliation target']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Retained body'], PublicActor::Anonymous, CommentAudience::Public);
    Comment::query()->whereKey($comment->id)->update(['reply_count' => 8, 'reaction_count' => 6, 'report_count' => 4, 'open_report_count' => 2]);
    $service = app(CommentStateReconciler::class);

    $audit = $service->reconcile($target, 1);
    expect($audit['drifted'])->toBe(1)
        ->and($audit['replyCountMismatches'])->toBe(1)
        ->and($comment->fresh()->reply_count)->toBe(8);

    $repair = $service->reconcile($target, 1, repair: true);
    expect($repair['repaired'])->toBe(1)
        ->and($repair['remaining'])->toBe(0)
        ->and($repair['healthy'])->toBeTrue()
        ->and($comment->fresh()->only(['reply_count', 'reaction_count', 'report_count', 'open_report_count', 'body']))
        ->toBe(['reply_count' => 0, 'reaction_count' => 0, 'report_count' => 0, 'open_report_count' => 0, 'body' => 'Retained body']);
});

it('limits native reconciliation repairs to the selected target', function (): void {
    config(['nvl-comments.anonymous.enabled' => true]);
    $first = TestCommentTarget::create(['name' => 'First target']);
    $second = TestCommentTarget::create(['name' => 'Second target']);
    $writer = app(CommentWriter::class);
    $one = $writer->create($first, ['body' => 'First'], PublicActor::Anonymous, CommentAudience::Public);
    $two = $writer->create($second, ['body' => 'Second'], PublicActor::Anonymous, CommentAudience::Public);
    Comment::query()->whereIn('id', [$one->id, $two->id])->update(['reaction_count' => 9]);

    app(CommentStateReconciler::class)->reconcile($first, 1, repair: true);

    expect($one->fresh()->reaction_count)->toBe(0)->and($two->fresh()->reaction_count)->toBe(9);
});

it('repairs native reply lineage from its canonical parent chain', function (): void {
    config(['nvl-comments.anonymous.enabled' => true]);
    $target = TestCommentTarget::create(['name' => 'Reply lineage']);
    $writer = app(CommentWriter::class);
    $parent = $writer->create($target, ['body' => 'Root'], PublicActor::Anonymous, CommentAudience::Public);
    $reply = $writer->create($target, ['body' => 'Reply', 'parentId' => $parent->id], PublicActor::Anonymous, CommentAudience::Public);
    Comment::query()->whereKey($reply->id)->update(['root_id' => null, 'depth' => 0]);

    $result = app(CommentStateReconciler::class)->reconcile($target, 1, repair: true);

    expect($result['threadMismatches'])->toBe(1)
        ->and($result['remaining'])->toBe(0)
        ->and($reply->fresh()->root_id)->toBe($parent->id)
        ->and($reply->fresh()->depth)->toBe(1);
});

it('reports missing canonical targets without deleting retained native comments', function (): void {
    config(['nvl-comments.anonymous.enabled' => true]);
    $target = TestCommentTarget::create(['name' => 'Missing target']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Retained'], PublicActor::Anonymous, CommentAudience::Public);
    DB::table($target->getTable())->where('id', $target->id)->delete();

    $result = app(CommentStateReconciler::class)->reconcile(null, 1, repair: true);

    expect($result['missingTargetComments'])->toBe(1)
        ->and($result['healthy'])->toBeFalse()
        ->and($comment->fresh()->body)->toBe('Retained');
});

it('creates an anonymous public comment only when the host enables it', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $writer = app(CommentWriter::class);

    config()->set('nvl-comments.anonymous.enabled', true);

    $comment = $writer->create(
        $target,
        ['body' => 'A public comment'],
        PublicActor::Anonymous,
        CommentAudience::Public,
    );

    expect($comment->exists)->toBeTrue();
    expect($comment->body)->toBe('A public comment');
    expect($comment->actor_type)->toBeNull();
    expect($comment->actor_id)->toBeNull();
});

it('rejects anonymous creation until explicitly enabled', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    config()->set('nvl-comments.anonymous.enabled', false);

    app(CommentWriter::class)->create(
        $target,
        ['body' => 'Denied'],
        PublicActor::Anonymous,
        CommentAudience::Public,
    );
})->throws(AuthorizationException::class);

it('replays an identical keyed creation and rejects changed content', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    config()->set('nvl-comments.anonymous.enabled', true);
    config()->set('nvl-comments.idempotency.digest_key', 'native-comments-test-key');
    $writer = app(CommentWriter::class);
    $key = (string) Str::uuid();
    $input = ['body' => 'Original', 'idempotency_key' => $key];

    $created = $writer->create($target, $input, PublicActor::Anonymous, CommentAudience::Public);
    $replayed = $writer->create($target, $input, PublicActor::Anonymous, CommentAudience::Public);

    expect($replayed->id)->toBe($created->id);
    expect($replayed->wasRecentlyCreated)->toBeFalse();

    $writer->create($target, ['body' => 'Changed', 'idempotency_key' => $key], PublicActor::Anonymous, CommentAudience::Public);
})->throws(CommentIdempotencyConflictException::class);

it('does not let another actor claim an existing idempotency key', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $first = NativeCommentActor::create(['name' => 'First']);
    $second = NativeCommentActor::create(['name' => 'Second']);
    $key = (string) Str::uuid();
    $writer = app(CommentWriter::class);
    $writer->create($target, ['body' => 'Same content', 'idempotency_key' => $key], $first);

    $writer->create($target, ['body' => 'Same content', 'idempotency_key' => $key], $second);
})->throws(CommentIdempotencyConflictException::class);

it('inherits a private parent and conceals it from another member', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $first = NativeCommentActor::create(['name' => 'First']);
    $second = NativeCommentActor::create(['name' => 'Second']);
    $writer = app(CommentWriter::class);
    $parent = $writer->create($target, ['body' => 'Private parent', 'visibility' => 'private'], $first, CommentAudience::Member);
    $reply = $writer->create($target, ['body' => 'Reply', 'parentId' => $parent->id], $first, CommentAudience::Member);

    expect($reply->visibility)->toBe(CommentVisibility::Private);
    expect($reply->parent_id)->toBe($parent->id);
    expect(fn () => $writer->create($target, ['body' => 'Intrusion', 'parentId' => $parent->id], $second, CommentAudience::Member))
        ->toThrow(ModelNotFoundException::class);
});

it('rejects a caller-mutated target identity before creation', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $target->setTable('comment_test_targets_other');

    app(CommentWriter::class)->create($target, ['body' => 'Unsafe'], $actor);
})->throws(InvalidCommentMutationException::class);

it('deletes a comment through the native revision contract', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    $comment = $writer->create($target, ['body' => 'To delete'], $actor, CommentAudience::Public);

    $writer->delete($comment, $comment->revision, $actor, CommentAudience::Public);

    $deleted = Comment::withTrashed()->findOrFail($comment->id);
    expect($deleted->trashed())->toBeTrue();

    $restored = $writer->restore($comment->id, $deleted->revision, $actor, CommentAudience::Public);
    expect($restored->trashed())->toBeFalse();
});

it('preserves a previous revision when the author updates content', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    $comment = $writer->create($target, ['body' => 'Before'], $actor, CommentAudience::Public);

    $updated = $writer->update($comment, [
        'body' => 'After',
        'expected_revision' => $comment->revision,
    ], $actor, CommentAudience::Public);

    expect($updated->body)->toBe('After');
    expect($updated->revision)->toBe($comment->revision + 1);
    expect($updated->revisions()->first()?->body)->toBe('Before');
});

it('rejects stale content writes without changing the persisted comment', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    $comment = $writer->create($target, ['body' => 'Before'], $actor);
    $writer->update($comment, ['body' => 'After', 'expected_revision' => $comment->revision], $actor);

    expect(fn () => $writer->update($comment, ['body' => 'Stale', 'expected_revision' => $comment->revision], $actor))
        ->toThrow(StaleCommentException::class);
    expect($comment->fresh()?->body)->toBe('After');
});

it('compiles rich documents and preserves a revision on rich update', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    config()->set('nvl-comments.mentions.enabled', true);
    $writer = app(CommentWriter::class);
    $before = ['version' => 1, 'blocks' => [
        ['type' => 'paragraph', 'children' => [['type' => 'text', 'text' => 'Before']]],
    ]];
    $after = ['version' => 1, 'blocks' => [
        ['type' => 'paragraph', 'children' => [['type' => 'text', 'text' => 'After']]],
    ]];

    $comment = $writer->createRich($target, ['document' => $before], $actor, CommentAudience::Public);
    $updated = $writer->updateRich($comment, [
        'document' => $after,
        'expected_revision' => $comment->revision,
    ], $actor, CommentAudience::Public);

    expect($comment->body)->toBe('Before');
    expect($updated->body)->toBe('After');
    expect($updated->revisions()->first()?->body)->toBe('Before');
});

it('restores a historical content snapshot as a new revision', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    $comment = $writer->create($target, ['body' => 'Before'], $actor, CommentAudience::Public);
    $updated = $writer->update($comment, [
        'body' => 'After',
        'expected_revision' => $comment->revision,
    ], $actor);
    $history = $updated->revisions()->firstOrFail();

    $restored = $writer->restoreRevision($updated, $history, $updated->revision, $actor);

    expect($restored->body)->toBe('Before');
    expect($restored->revision)->toBe($updated->revision + 1);
});

it('anonymizes an authorized comment without an installed Media provider', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $author = NativeCommentActor::create(['name' => 'Author']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Personal content'], $author);
    $policy = new NativeModerationPolicy;
    app()->instance(CommentAuthorization::class, $policy);
    app()->instance(CommentQueryScope::class, $policy);

    $anonymized = app(CommentWriter::class)->anonymize($comment, [
        'expected_revision' => $comment->revision,
        'reason' => 'Requested removal',
    ], SystemActor::Background);

    expect($anonymized->anonymized_at)->not->toBeNull();
    expect($anonymized->body)->not->toBe('Personal content');
});

it('serializes comment events with scalar actor facts only', function (): void {
    $principal = NativeCommentActor::create(['name' => 'Author']);
    $principal->setAttribute('private_token', 'event-private-token');
    $actor = CommentActor::inspect($principal);
    $events = [
        new CommentChanged('comment', 'target', 'target-id', CommentChangeOperation::Created, 1, $actor),
        new CommentReactionChanged('comment', 'like', true, $actor),
        new CommentReported('comment', 'report', $actor),
    ];

    foreach ($events as $event) {
        expect($event->actor)->toBe(['type' => $actor->type, 'id' => $actor->id, 'system' => false]);
        expect(serialize($event))->not->toContain('event-private-token', NativeCommentActor::class, CommentActor::class);
        expect(unserialize(serialize($event))->actor)->toBe($event->actor);
    }
});

it('rejects anonymous principals at the attachment anonymization boundary', function (): void {
    app(CommentAnonymizer::class)->anonymize(
        'unknown-comment',
        ['expected_revision' => 1, 'reason' => 'Erase personal data'],
        CommentActor::anonymous(),
        CommentAudience::Management,
    );
})->throws(AuthorizationException::class);

it('preserves omitted update fields and clears an explicitly nullable locale', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    $comment = $writer->create($target, ['body' => 'Before', 'locale' => 'en', 'tags' => ['retained']], $actor);

    $updated = $writer->update($comment, ['body' => 'After', 'expected_revision' => $comment->revision], $actor);
    expect($updated->locale)->toBe('en');
    expect($updated->tags)->toBe(['retained']);

    $cleared = $writer->update($updated, ['body' => 'After', 'expected_revision' => $updated->revision, 'locale' => null], $actor);
    expect($cleared->locale)->toBeNull();
    expect($cleared->tags)->toBe(['retained']);
});

it('rejects protected creation attributes before persisting a comment', function (string $field): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);

    expect(fn () => app(CommentWriter::class)->create($target, ['body' => 'Denied', $field => 'forged'], $actor))
        ->toThrow(InvalidCommentMutationException::class);
    expect(Comment::query()->count())->toBe(0);
})->with(['actor_id', 'owner_scope_key', 'revision', 'status']);

it('publishes comment facts after the host commit and discards rolled back facts', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    Event::fake([CommentChanged::class]);
    $connection = (new Comment)->getConnection();

    $connection->beginTransaction();
    $rolledBack = $writer->create($target, ['body' => 'Rolled back'], $actor);
    Event::assertNothingDispatched();
    $connection->rollBack();
    expect(Comment::query()->find($rolledBack->id))->toBeNull();
    Event::assertNothingDispatched();

    $connection->beginTransaction();
    $committed = $writer->create($target, ['body' => 'Committed'], $actor);
    Event::assertNothingDispatched();
    $connection->commit();
    Event::assertDispatched(CommentChanged::class, static fn ($event): bool => $event->commentId === $committed->id);
    Event::assertDispatchedTimes(CommentChanged::class, 1);
});

it('caps configured content limits at the capacity of the body column', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    config()->set([
        'nvl-comments.mentions.enabled' => true,
        'nvl-comments.content.maximum_bytes' => 100_000,
        'nvl-comments.rich_text.maximum_bytes' => 131_072,
    ]);
    $writer = app(CommentWriter::class);
    $rich = fn (int $bytes): array => ['document' => ['version' => 1, 'blocks' => [
        ['type' => 'paragraph', 'children' => [['type' => 'text', 'text' => str_repeat('a', $bytes)]]],
    ]]];

    expect(fn () => $writer->create($target, ['body' => str_repeat('a', 65_536)], $actor))
        ->toThrow(InvalidCommentMutationException::class, 'Comment content exceeds 65535 bytes.')
        ->and(fn () => $writer->createRich($target, $rich(65_536), $actor))
        ->toThrow(InvalidCommentMutationException::class)
        ->and(Comment::query()->count())->toBe(0);

    expect(strlen($writer->create($target, ['body' => str_repeat('a', 65_535)], $actor)->body))->toBe(65_535)
        ->and(strlen($writer->createRich($target, $rich(60_000), $actor)->body))->toBe(60_000);
});
