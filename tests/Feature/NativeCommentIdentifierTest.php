<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentModeration;
use Nvl\Comments\Services\CommentReader;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Support\Enums\PublicActor;
use Nvl\Support\Enums\SystemActor;

/** @return list<mixed> Every value bound to a statement while the operation ran. */
function commentStatementBindings(Closure $operation): array
{
    $bindings = [];
    DB::listen(function (QueryExecuted $query) use (&$bindings): void {
        $bindings = [...$bindings, ...$query->bindings];
    });
    $operation();

    return $bindings;
}

it('resolves malformed route identifiers to nothing without querying a UUID column', function (): void {
    $comment = app(CommentWriter::class)->create(TestCommentTarget::create(['name' => 'Article']), ['body' => 'Body'], NativeCommentActor::create(['name' => 'Author']));
    $comment->delete();

    $bindings = commentStatementBindings(function () use ($comment): void {
        expect((new Comment)->resolveRouteBinding('not-a-uuid'))->toBeNull()
            ->and((new Comment)->resolveSoftDeletableRouteBinding('not-a-uuid'))->toBeNull()
            ->and((new Comment)->resolveRouteBinding('not-a-uuid', 'id'))->toBeNull()
            ->and((new Comment)->resolveRouteBinding($comment->id))->toBeNull()
            ->and((new Comment)->resolveSoftDeletableRouteBinding($comment->id)?->is($comment))->toBeTrue();
    });

    expect($bindings)->not->toContain('not-a-uuid');
});

it('reports malformed comment, parent, revision and report identifiers as not found without querying', function (Closure $operation): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    $comment = $writer->create($target, ['body' => 'Before'], $actor);
    $comment = $writer->update($comment, ['body' => 'After', 'expected_revision' => $comment->revision], $actor);
    $thrown = null;

    $bindings = commentStatementBindings(function () use ($operation, $target, $actor, $comment, &$thrown): void {
        try {
            $operation($target, $actor, $comment);
        } catch (ModelNotFoundException $exception) {
            $thrown = $exception;
        }
    });

    expect($thrown)->toBeInstanceOf(ModelNotFoundException::class)
        ->and($bindings)->not->toContain('not-a-uuid');
})->with([
    'comment history' => [fn ($target, $actor) => app(CommentReader::class)->revisions('not-a-uuid', $actor)],
    'parent page' => [fn ($target) => app(CommentReader::class)->paginate($target, PublicActor::Anonymous, parentId: 'not-a-uuid')],
    'reply parent' => [fn ($target, $actor) => app(CommentWriter::class)->create($target, ['body' => 'Reply', 'parentId' => 'not-a-uuid'], $actor)],
    'updated comment' => [fn ($target, $actor) => app(CommentWriter::class)->update('not-a-uuid', ['body' => 'Changed', 'expected_revision' => 1], $actor)],
    'restored revision' => [fn ($target, $actor, $comment) => app(CommentWriter::class)->restoreRevision($comment, 'not-a-uuid', $comment->revision, $actor)],
    'resolved report' => [fn () => app(CommentModeration::class)->resolveReport('not-a-uuid', ['status' => CommentReportStatus::Resolved, 'expected_revision' => 1, 'resolution' => 'Handled'], SystemActor::Background)],
]);
