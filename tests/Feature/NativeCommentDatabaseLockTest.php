<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nvl\Comments\Exceptions\CommentMutationBusyException;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;

/** Hold the mutation lock of one comment for another process on the shared database lock store. */
function holdCommentDatabaseLock(string $commentId, int $expiration): void
{
    DB::table('cache_locks')->insert([
        'key' => config('cache.prefix').'nvl:comments:mutation:'.hash('sha256', $commentId),
        'owner' => 'another-process',
        'expiration' => $expiration,
    ]);
}

beforeEach(function (): void {
    config([
        'cache.stores.database' => ['driver' => 'database', 'connection' => null, 'table' => 'cache', 'lock_connection' => null, 'lock_table' => 'cache_locks'],
        'nvl-comments.locks.mutation.store' => 'database',
        'nvl-comments.mutation_lock.wait_seconds' => 1,
    ]);
});

it('reports a contended database lock as busy without aborting the host transaction', function (): void {
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $comment = app(CommentWriter::class)->create(TestCommentTarget::create(['name' => 'Article']), ['body' => 'Before'], $actor);
    holdCommentDatabaseLock($comment->id, now()->addMinute()->getTimestamp());

    expect(fn () => DB::transaction(fn () => app(CommentWriter::class)->update($comment, ['body' => 'After', 'expected_revision' => 1], $actor)))
        ->toThrow(CommentMutationBusyException::class)
        ->and(DB::table('cache_locks')->where('owner', 'another-process')->count())->toBe(1)
        ->and($comment->fresh()->body)->toBe('Before');
});

it('takes over an expired database lock inside a host transaction', function (): void {
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $comment = app(CommentWriter::class)->create(TestCommentTarget::create(['name' => 'Article']), ['body' => 'Before'], $actor);
    holdCommentDatabaseLock($comment->id, now()->subMinute()->getTimestamp());

    $updated = DB::transaction(fn () => app(CommentWriter::class)->update($comment, ['body' => 'After', 'expected_revision' => 1], $actor));

    expect($updated->body)->toBe('After');
});
