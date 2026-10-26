<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Services\CommentModeration;
use Nvl\Comments\Services\CommentReader;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\NativeModerationPolicy;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Support\Enums\SystemActor;

it('denies system moderation without an explicit host policy grant', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Review me'], $actor, CommentAudience::Public);

    app(CommentModeration::class)->moderate($comment, [
        'status' => CommentStatus::Rejected,
        'expected_revision' => $comment->revision,
    ], SystemActor::Background);
})->throws(ModelNotFoundException::class);

it('accepts a background moderator only through an explicit host policy', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Review me'], $actor, CommentAudience::Public);
    $policy = new NativeModerationPolicy;
    app()->instance(CommentAuthorization::class, $policy);
    app()->instance(CommentQueryScope::class, $policy);

    $updated = app(CommentModeration::class)->moderate($comment, [
        'status' => CommentStatus::Rejected,
        'expected_revision' => $comment->revision,
    ], SystemActor::Background);

    expect($updated->status)->toBe(CommentStatus::Rejected);
    expect($updated->moderated_by_type)->toBe('system');
});

it('reports idempotently and resolves through explicit moderation authority', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $author = NativeCommentActor::create(['name' => 'Author']);
    $reporter = NativeCommentActor::create(['name' => 'Reporter']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Review me'], $author, CommentAudience::Public);
    $moderation = app(CommentModeration::class);

    $first = $moderation->report($comment, ['reason' => 'Abuse'], $reporter);
    $again = $moderation->report($comment, ['reason' => 'Abuse'], $reporter);
    expect($again->id)->toBe($first->id);
    expect($comment->fresh()?->open_report_count)->toBe(1);

    $policy = new NativeModerationPolicy;
    app()->instance(CommentAuthorization::class, $policy);
    app()->instance(CommentQueryScope::class, $policy);
    $resolved = app(CommentModeration::class)->resolveReport($first, [
        'status' => CommentReportStatus::Resolved,
        'expected_revision' => $comment->fresh()?->revision,
        'resolution' => 'Reviewed',
    ], SystemActor::Background);

    expect($resolved->status)->toBe(CommentReportStatus::Resolved);
    expect($comment->fresh()?->open_report_count)->toBe(0);
    $rows = app(CommentReader::class)->reports($comment, SystemActor::Background)->items();
    expect($rows)->toHaveCount(1);
    expect($rows[0])->not->toHaveKey('reporterId');
});
