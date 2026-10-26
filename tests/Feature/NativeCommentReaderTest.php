<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentReader;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Support\Enums\PublicActor;

it('returns audience scoped public rows without persisted actor identity', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $author = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    $writer->create($target, ['body' => 'Public'], $author, CommentAudience::Public);
    $writer->create($target, ['body' => 'Private', 'visibility' => 'private'], $author, CommentAudience::Member);

    $reader = app(CommentReader::class);
    $public = $reader->paginate($target, PublicActor::Anonymous);
    $member = $reader->paginate($target, $author, CommentAudience::Member);

    expect($public->total())->toBe(1);
    expect($member->total())->toBe(2);
    expect($public->items()[0]['body'])->toBe('Public');
    expect($public->items()[0])->not->toHaveKey('actorId');
    expect($public->items()[0])->not->toHaveKey('actorType');
});

it('returns explicit empty owner summaries and bounded latest rows', function (): void {
    $first = TestCommentTarget::create(['name' => 'First']);
    $second = TestCommentTarget::create(['name' => 'Second']);
    $author = NativeCommentActor::create(['name' => 'Author']);
    app(CommentWriter::class)->create($first, ['body' => 'Visible'], $author, CommentAudience::Public);

    $result = app(CommentReader::class)->forOwners([$first, $second], PublicActor::Anonymous, latestLimit: 1);
    $type = $first->getMorphClass();

    expect($result['order'])->toHaveCount(2);
    expect($result['values'][$type][$first->id]['visibleCount'])->toBe(1);
    expect($result['values'][$type][$first->id]['latest'])->toHaveCount(1);
    expect($result['values'][$type][$second->id]['visibleCount'])->toBe(0);
    expect($result['values'][$type][$second->id]['latest'])->toBe([]);
});

it('keeps member visibility tied to the explicit actor on one policy instance', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $firstActor = NativeCommentActor::create(['name' => 'First']);
    $secondActor = NativeCommentActor::create(['name' => 'Second']);
    $writer = app(CommentWriter::class);
    $writer->create($target, ['body' => 'First private', 'visibility' => 'private'], $firstActor, CommentAudience::Member);
    $writer->create($target, ['body' => 'Second private', 'visibility' => 'private'], $secondActor, CommentAudience::Member);
    $reader = app(CommentReader::class);

    $firstRows = $reader->paginate($target, $firstActor, CommentAudience::Member)->items();
    $secondRows = $reader->paginate($target, $secondActor, CommentAudience::Member)->items();

    expect(array_column($firstRows, 'body'))->toBe(['First private']);
    expect(array_column($secondRows, 'body'))->toBe(['Second private']);
});

it('keeps owner batch data queries bounded as owner count grows', function (): void {
    $owners = [];
    for ($index = 0; $index < 8; $index++) {
        $owners[] = TestCommentTarget::create(['name' => "Article {$index}"]);
    }
    $actor = NativeCommentActor::create(['name' => 'Author']);
    app(CommentWriter::class)->create($owners[0], ['body' => 'Visible'], $actor, CommentAudience::Public);
    $reader = app(CommentReader::class);
    $table = (new Comment)->getTable();
    $quoted = DB::connection()->getQueryGrammar()->wrapTable($table);
    $queries = [];
    DB::listen(static function ($event) use (&$queries, $quoted): void {
        if (str_contains($event->sql, $quoted)) {
            $queries[] = $event->sql;
        }
    });

    $reader->forOwners([$owners[0]], PublicActor::Anonymous);
    $single = count($queries);
    $queries = [];
    $reader->forOwners($owners, PublicActor::Anonymous);
    $many = count($queries);

    expect($single)->toBeGreaterThan(0);
    expect($many)->toBe($single);
});

it('keeps revision history with the author and omits editor identity from its rows', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $author = NativeCommentActor::create(['name' => 'Author']);
    $stranger = NativeCommentActor::create(['name' => 'Stranger']);
    $writer = app(CommentWriter::class);
    $comment = $writer->create($target, ['body' => 'Before'], $author);
    $writer->update($comment, ['body' => 'After', 'expected_revision' => $comment->revision], $author);
    $reader = app(CommentReader::class);

    $history = $reader->revisions($comment, $author);
    expect($history->total())->toBe(1);
    expect($history->items()[0]['body'])->toBe('Before');
    expect($history->items()[0])->not->toHaveKey('editedBy');
    expect(fn () => $reader->revisions($comment, $stranger))
        ->toThrow(AuthorizationException::class);
});
