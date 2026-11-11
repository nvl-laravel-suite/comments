<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Nvl\Comments\Exceptions\CommentIdempotencyConflictException;
use Nvl\Comments\Services\CommentMetadataRegistry;
use Nvl\Comments\Services\CommentStateReconciler;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\TestCommentMetadataSchema;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;

it('keeps idempotent replays and the metadata index valid across an application key rotation when the digest key is pinned to the old key', function (): void {
    $oldKey = config('app.key');
    config(['nvl-comments.metadata.schemas' => [TestCommentMetadataSchema::class]]);
    app()->forgetInstance(CommentMetadataRegistry::class);
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $input = ['body' => 'Original', 'idempotency_key' => (string) Str::uuid(), 'metadata' => ['workflow_sequence' => 4]];
    $comment = app(CommentWriter::class)->create($target, $input, $actor);

    config(['app.key' => 'base64:'.base64_encode(str_repeat('b', 32))]);
    $unpinned = app(CommentStateReconciler::class)->reconcile($target, 1);
    expect(fn () => app(CommentWriter::class)->create($target, $input, $actor))->toThrow(CommentIdempotencyConflictException::class)
        ->and($unpinned['staleMetadataIndexValues'])->toBe(1);

    config(['nvl-comments.idempotency.digest_key' => $oldKey]);
    expect(app(CommentWriter::class)->create($target, $input, $actor)->is($comment))->toBeTrue()
        ->and(app(CommentStateReconciler::class)->reconcile($target, 1)['staleMetadataIndexValues'])->toBe(0);
});

it('rebuilds the metadata index under a new digest key with a reconciliation repair', function (): void {
    config(['nvl-comments.metadata.schemas' => [TestCommentMetadataSchema::class], 'nvl-comments.metadata.digest_key' => 'first-metadata-key']);
    app()->forgetInstance(CommentMetadataRegistry::class);
    $target = TestCommentTarget::create(['name' => 'Article']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Indexed', 'metadata' => ['workflow_sequence' => 4]], NativeCommentActor::create(['name' => 'Author']));
    $before = $comment->metadataValues()->value('value_hash');

    config(['nvl-comments.metadata.digest_key' => 'second-metadata-key']);
    $repair = app(CommentStateReconciler::class)->reconcile($target, 1, repair: true);

    expect($repair['staleMetadataIndexValues'])->toBe(1)
        ->and($repair['healthy'])->toBeTrue()
        ->and($comment->metadataValues()->value('value_hash'))->not->toBe($before)
        ->and(app(CommentStateReconciler::class)->reconcile($target, 1)['staleMetadataIndexValues'])->toBe(0);
});
