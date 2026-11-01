<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Events\CommentMentionsChanged;
use Nvl\Comments\Exceptions\InvalidCommentLifecycleException;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentMetadataValue;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Services\CommentMentionResourceRegistry;
use Nvl\Comments\Services\CommentMetadataRegistry;
use Nvl\Comments\Services\CommentMutationLock;
use Nvl\Comments\Services\CommentStateReconciler;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\NativeCommentMentionResolver;
use Nvl\Comments\Tests\Fixtures\TestCommentMetadataSchema;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;

it('rolls back revision history and content when a required update write is vetoed', function (string $stage): void {
    $target = TestCommentTarget::create(['name' => 'Update rollback']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Original'], $actor);
    Event::listen('eloquent.creating: '.CommentRevision::class, static fn (): bool => $stage !== 'revision');
    Event::listen('eloquent.updating: '.Comment::class, static fn (Comment $updating): bool => $stage !== 'comment' || $updating->id !== $comment->id);
    Event::fake([CommentChanged::class]);

    expect(fn () => app(CommentWriter::class)->update($comment, ['body' => 'Vetoed', 'expected_revision' => $comment->revision], $actor))
        ->toThrow(InvalidCommentMutationException::class);
    expect($comment->fresh()->body)->toBe('Original')
        ->and($comment->fresh()->revision)->toBe(1)
        ->and($comment->revisions()->count())->toBe(0);
    Event::assertNotDispatched(CommentChanged::class);
})->with(['revision', 'comment']);

it('rolls back the whole reply lifecycle when its parent counter write is vetoed', function (string $operation): void {
    $target = TestCommentTarget::create(['name' => 'Reply rollback']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    $parent = $writer->create($target, ['body' => 'Parent'], $actor);
    $reply = $operation === 'delete' ? $writer->create($target, ['body' => 'Reply', 'parentId' => $parent->id], $actor) : null;
    Event::listen('eloquent.updating: '.Comment::class, static fn (Comment $updating): bool => $updating->id !== $parent->id);
    Event::fake([CommentChanged::class]);

    expect(fn () => $reply === null
        ? $writer->create($target, ['body' => 'Vetoed reply', 'parentId' => $parent->id], $actor)
        : $writer->delete($reply, $reply->revision, $actor))
        ->toThrow($reply === null ? InvalidCommentMutationException::class : InvalidCommentLifecycleException::class);
    expect($parent->fresh()->reply_count)->toBe($reply === null ? 0 : 1)
        ->and(Comment::query()->count())->toBe($reply === null ? 1 : 2);
    if ($reply !== null) {
        expect($reply->fresh()->revision)->toBe(1)
            ->and($reply->fresh()->deleted_by)->toBeNull();
    }
    Event::assertNotDispatched(CommentChanged::class);
})->with(['create', 'delete']);

it('repairs missing and stale metadata indexes idempotently without changing user content', function (): void {
    config(['nvl-comments.metadata.schemas' => [TestCommentMetadataSchema::class], 'nvl-comments.metadata.digest_key' => 'native-repair-key']);
    app()->forgetInstance(CommentMetadataRegistry::class);
    $target = TestCommentTarget::create(['name' => 'Metadata repair']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Retained', 'metadata' => ['workflow_event' => 'queued', 'workflow_sequence' => 4]], $actor);
    $expected = $comment->metadataValues()->orderBy('field_name')->get(['schema_namespace', 'field_name', 'value_type', 'value_hash'])->toArray();
    $comment->metadataValues()->where('field_name', 'event')->delete();
    $comment->metadataValues()->where('field_name', 'sequence')->update(['value_hash' => str_repeat('0', 64)]);
    $reconciler = app(CommentStateReconciler::class);
    $audit = $reconciler->reconcile($target, 1);
    expect($audit['missingMetadataIndexValues'])->toBe(1)
        ->and($audit['staleMetadataIndexValues'])->toBe(1)
        ->and($comment->metadataValues()->count())->toBe(1);

    $repair = $reconciler->reconcile($target, 1, repair: true);
    expect($repair['repaired'])->toBe(1)->and($repair['healthy'])->toBeTrue()
        ->and($comment->metadataValues()->orderBy('field_name')->get(['schema_namespace', 'field_name', 'value_type', 'value_hash'])->toArray())->toBe($expected)
        ->and($comment->fresh()->body)->toBe('Retained')->and($comment->fresh()->revision)->toBe(1)
        ->and($reconciler->reconcile($target, 1, repair: true)['repaired'])->toBe(0);
});

it('removes stale metadata indexes from deleted comments instead of resurrecting them', function (): void {
    config(['nvl-comments.metadata.schemas' => [TestCommentMetadataSchema::class], 'nvl-comments.metadata.digest_key' => 'native-deleted-repair-key']);
    app()->forgetInstance(CommentMetadataRegistry::class);
    $target = TestCommentTarget::create(['name' => 'Deleted metadata']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    $comment = $writer->create($target, ['body' => 'Deleted', 'metadata' => ['workflow_event' => 'queued']], $actor);
    $stale = $comment->metadataValues()->sole()->getAttributes();
    $writer->delete($comment, $comment->revision, $actor);
    CommentMetadataValue::query()->insert($stale);

    $repair = app(CommentStateReconciler::class)->reconcile($target, 1, repair: true);

    expect($repair['staleMetadataIndexValues'])->toBe(1)->and($repair['healthy'])->toBeTrue()
        ->and(CommentMetadataValue::query()->where('comment_id', $comment->id)->exists())->toBeFalse()
        ->and(Comment::withTrashed()->findOrFail($comment->id)->trashed())->toBeTrue();
});

it('repairs rich mention rows and body projections from snapshots without resolving live resources', function (): void {
    config()->set('nvl-comments.mentions.enabled', true);
    app(CommentMentionResourceRegistry::class)->register('member', NativeCommentMentionResolver::class);
    $target = TestCommentTarget::create(['name' => 'Rich repair']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $comment = app(CommentWriter::class)->createRich($target, ['document' => ['version' => 1, 'blocks' => [[
        'type' => 'paragraph', 'children' => [
            ['type' => 'text', 'text' => 'Hello '],
            ['type' => 'mention', 'tokenId' => (string) Str::uuid(), 'resource' => 'member', 'id' => 'member-1'],
        ],
    ]]]], $actor);
    $snapshot = $comment->document;
    $body = $comment->body;
    $comment->mentions()->delete();
    Comment::query()->whereKey($comment->id)->update(['body' => 'Drifted body']);
    NativeCommentMentionResolver::$resolutions = 0;
    Event::fake([CommentChanged::class, CommentMentionsChanged::class]);
    $reconciler = app(CommentStateReconciler::class);
    $audit = $reconciler->reconcile($target, 1);
    expect($audit['documentMentionMismatches'])->toBe(1)->and($audit['bodyProjectionMismatches'])->toBe(1)
        ->and($comment->fresh()->body)->toBe('Drifted body');

    $repair = $reconciler->reconcile($target, 1, repair: true);
    expect($repair['healthy'])->toBeTrue()->and($repair['repaired'])->toBe(1)
        ->and($comment->fresh()->body)->toBe($body)->and($comment->fresh()->document)->toBe($snapshot)
        ->and($comment->mentions()->sole()->resource_id)->toBe('member-1')
        ->and($comment->revisions()->count())->toBe(0)
        ->and($reconciler->reconcile($target, 1, repair: true)['repaired'])->toBe(0)
        ->and(NativeCommentMentionResolver::$resolutions)->toBe(0);
    Event::assertNothingDispatched();
});

it('retains native mutation locks through savepoint rollback until the source root rolls back', function (): void {
    $commentId = (string) Str::uuid();
    $key = 'nvl:comments:mutation:'.hash('sha256', $commentId);
    $contender = Cache::store('file')->lock($key, 10, 'independent-contender');
    $source = 'comments_savepoint_regression';
    config()->set('database.connections.'.$source, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    config()->set('nvl-comments.connection', $source);
    config()->set('nvl-comments.locks.mutation.store', 'file');
    $connection = DB::connection($source);

    try {
        $connection->beginTransaction();
        $connection->beginTransaction();
        app(CommentMutationLock::class)->execute($commentId, static fn (): null => null);
        $connection->rollBack();
        expect($connection->transactionLevel())->toBe(1)->and($contender->get())->toBeFalse();
        $connection->rollBack();
        expect($connection->transactionLevel())->toBe(0)->and($contender->get())->toBeTrue();
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        $contender->release();
        DB::purge($source);
    }
});
