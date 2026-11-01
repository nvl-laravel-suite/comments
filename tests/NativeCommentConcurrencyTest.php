<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Nvl\Comments\Exceptions\StaleCommentException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\NativeCommentRace;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Comments\Tests\NativeCommentsConcurrencyTestCase;

uses(NativeCommentsConcurrencyTestCase::class);

it('commits exactly one native comment for simultaneous identical idempotency receipts', function (): void {
    $target = TestCommentTarget::create(['name' => 'Competing receipt']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $key = (string) Str::uuid();
    $worker = static function () use ($target, $actor, $key): array {
        $comment = app(CommentWriter::class)->create($target, ['body' => 'Exactly one', 'idempotency_key' => $key], $actor);

        return ['id' => $comment->id, 'created' => $comment->wasRecentlyCreated];
    };
    $results = NativeCommentRace::run([$worker, $worker]);

    expect(array_column($results, 'ok'))->toBe([true, true])
        ->and(array_unique(array_column($results, 'pid')))->toHaveCount(2)
        ->and(array_unique(array_column($results, 'driver')))->toBe([getenv('NVL_COMMENTS_CONCURRENCY_DRIVER')])
        ->and(array_unique(array_column($results, 'id')))->toHaveCount(1)
        ->and(array_column($results, 'created'))->toContain(true, false)
        ->and(Comment::query()->where('idempotency_key', $key)->count())->toBe(1);
});

it('linearizes simultaneous native updates at one revision and rejects the stale writer', function (): void {
    $target = TestCommentTarget::create(['name' => 'Competing revisions']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Original'], $actor);
    $worker = static function (string $body) use ($comment, $actor): array {
        $updated = app(CommentWriter::class)->update($comment, ['body' => $body, 'expected_revision' => $comment->revision], $actor);

        return ['id' => $updated->id, 'body' => $updated->body];
    };
    $results = NativeCommentRace::run([static fn (): array => $worker('First writer'), static fn (): array => $worker('Second writer')]);
    $successes = array_values(array_filter($results, static fn (array $result): bool => $result['ok'] === true));
    $failures = array_values(array_filter($results, static fn (array $result): bool => $result['ok'] === false));

    expect($successes)->toHaveCount(1)->and($failures)->toHaveCount(1)
        ->and($failures[0]['exception'])->toBe(StaleCommentException::class)
        ->and(array_unique(array_column($results, 'pid')))->toHaveCount(2)
        ->and(array_unique(array_column($results, 'driver')))->toBe([getenv('NVL_COMMENTS_CONCURRENCY_DRIVER')])
        ->and($comment->fresh()->revision)->toBe(2)
        ->and($comment->fresh()->body)->toBe($successes[0]['body'])
        ->and($comment->revisions()->sole()->body)->toBe('Original');
});
