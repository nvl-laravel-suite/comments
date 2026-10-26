<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Comments\Exceptions\CommentIdempotencyConflictException;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Exceptions\StaleCommentException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\NativeModerationPolicy;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Support\Enums\PublicActor;
use Nvl\Support\Enums\SystemActor;

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
    $input = ['body' => 'Original', 'idempotencyKey' => $key];

    $created = $writer->create($target, $input, PublicActor::Anonymous, CommentAudience::Public);
    $replayed = $writer->create($target, $input, PublicActor::Anonymous, CommentAudience::Public);

    expect($replayed->id)->toBe($created->id);
    expect($replayed->wasRecentlyCreated)->toBeFalse();

    $writer->create($target, ['body' => 'Changed', 'idempotencyKey' => $key], PublicActor::Anonymous, CommentAudience::Public);
})->throws(CommentIdempotencyConflictException::class);

it('does not let another actor claim an existing idempotency key', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $first = NativeCommentActor::create(['name' => 'First']);
    $second = NativeCommentActor::create(['name' => 'Second']);
    $key = (string) Str::uuid();
    $writer = app(CommentWriter::class);
    $writer->create($target, ['body' => 'Same content', 'idempotencyKey' => $key], $first);

    $writer->create($target, ['body' => 'Same content', 'idempotencyKey' => $key], $second);
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
        'expectedRevision' => $comment->revision,
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
    $writer->update($comment, ['body' => 'After', 'expectedRevision' => $comment->revision], $actor);

    expect(fn () => $writer->update($comment, ['body' => 'Stale', 'expectedRevision' => $comment->revision], $actor))
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
        'expectedRevision' => $comment->revision,
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
        'expectedRevision' => $comment->revision,
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
        'expectedRevision' => $comment->revision,
        'reason' => 'Requested removal',
    ], SystemActor::Background);

    expect($anonymized->anonymized_at)->not->toBeNull();
    expect($anonymized->body)->not->toBe('Personal content');
});
