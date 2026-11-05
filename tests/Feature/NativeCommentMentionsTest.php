<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentMentionState;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Services\CommentMentionResourceRegistry;
use Nvl\Comments\Services\CommentMentions;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\NativeCommentMentionResolver;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Support\Enums\PublicActor;

it('resolves a registered private mention for a member and conceals it from public viewers', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    config()->set('nvl-comments.mentions.enabled', true);
    app(CommentMentionResourceRegistry::class)->register('member', NativeCommentMentionResolver::class);
    $document = ['version' => 1, 'blocks' => [[
        'type' => 'paragraph',
        'children' => [[
            'type' => 'mention',
            'tokenId' => (string) Str::uuid(),
            'resource' => 'member',
            'id' => 'member-1',
        ]],
    ]]];
    $comment = app(CommentWriter::class)->createRich($target, ['document' => $document], $actor, CommentAudience::Public);

    $member = app(CommentMentions::class)->resolve($comment, $actor, CommentAudience::Member);
    $public = app(CommentMentions::class)->resolve($comment, PublicActor::Anonymous);

    expect($member[0]['state'])->toBe(CommentMentionState::Resolved);
    expect($member[0]['resourceId'])->toBe('member-1');
    expect($public[0]['state'])->toBe(CommentMentionState::Restricted);
    expect($public[0]['resourceId'])->toBeNull();
});

it('rejects unregistered mention references without persisting the rich comment', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    config()->set('nvl-comments.mentions.enabled', true);
    $document = ['version' => 1, 'blocks' => [[
        'type' => 'paragraph',
        'children' => [[
            'type' => 'mention',
            'tokenId' => (string) Str::uuid(),
            'resource' => 'unknown',
            'id' => 'secret',
        ]],
    ]]];

    try {
        app(CommentWriter::class)->createRich($target, ['document' => $document], $actor);
        $this->fail('An unregistered resource was accepted.');
    } catch (InvalidCommentMutationException) {
        expect(Comment::query()->count())->toBe(0);
    }
});

it('rejects rich nodes that try to inject extra rendering fields', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    config()->set('nvl-comments.mentions.enabled', true);
    $document = ['version' => 1, 'blocks' => [[
        'type' => 'paragraph',
        'children' => [['type' => 'text', 'text' => 'Safe', 'html' => '<script>alert(1)</script>']],
    ]]];

    app(CommentWriter::class)->createRich($target, ['document' => $document], $actor);
})->throws(InvalidCommentMutationException::class);

it('rejects extra rich document fields before creating or revising comments', function (string $operation): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    config()->set('nvl-comments.mentions.enabled', true);
    $document = ['version' => 1, 'blocks' => [[
        'type' => 'paragraph',
        'children' => [['type' => 'text', 'text' => 'Safe']],
    ]]];
    $writer = app(CommentWriter::class);
    $comment = $operation === 'update' ? $writer->createRich($target, ['document' => $document], $actor) : null;
    $document['html'] = '<script>alert(1)</script>';

    expect(fn () => $comment === null
        ? $writer->createRich($target, ['document' => $document], $actor)
        : $writer->updateRich($comment, ['document' => $document, 'expected_revision' => $comment->revision], $actor))
        ->toThrow(InvalidCommentMutationException::class);

    expect(Comment::query()->count())->toBe($comment === null ? 0 : 1)
        ->and(CommentRevision::query()->count())->toBe(0);
    if ($comment !== null) {
        expect($comment->fresh()->revision)->toBe(1)
            ->and($comment->fresh()->body)->toBe('Safe');
    }
})->with(['create', 'update']);

it('suggests only registered bounded resources after target admission', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    config()->set('nvl-comments.mentions.enabled', true);
    app(CommentMentionResourceRegistry::class)->register('member', NativeCommentMentionResolver::class);

    $suggestions = app(CommentMentions::class)->suggest(
        $target,
        $actor,
        'member',
        'One',
        audience: CommentAudience::Member,
    );

    expect($suggestions)->toHaveCount(1);
    expect($suggestions[0]['id'])->toBe('member-1');
    expect($suggestions[0]['label'])->toBe('Member One');
});
