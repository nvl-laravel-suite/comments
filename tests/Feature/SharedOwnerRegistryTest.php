<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Comments\Exceptions\CommentTargetNotFoundException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentsDoctor;
use Nvl\Comments\Services\CommentTargetRegistry;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Comments\Tests\Fixtures\TestCommentTargetResolver;

beforeEach(function (): void {
    $this->originalOwnerMorphMap = Relation::morphMap();
});

afterEach(function (): void {
    Relation::morphMap($this->originalOwnerMorphMap, false);
});

it('uses declared shared identities while retaining comment resolvers and target allowlists', function (): void {
    config()->set('nvl-core.owners', ['post' => TestCommentTarget::class, 'private-post' => Comment::class]);
    $target = TestCommentTarget::create(['name' => 'Visible target']);
    $registry = app()->build(CommentTargetRegistry::class);
    $registry->register('article', TestCommentTargetResolver::class, 'post');

    expect($registry->resolve('article', $target->getKey())->is($target))->toBeTrue()
        ->and(fn () => $registry->resolve('private-post', $target->getKey()))->toThrow(CommentTargetNotFoundException::class)
        ->and(fn () => $registry->resolve('article', 'missing'))->toThrow(CommentTargetNotFoundException::class);
});

it('rejects comment resolvers returning a different shared owner model', function (): void {
    config()->set('nvl-core.owners', ['post' => Comment::class]);
    $target = TestCommentTarget::create(['name' => 'Wrong identity']);
    $registry = app()->build(CommentTargetRegistry::class);
    $registry->register('article', TestCommentTargetResolver::class, 'post');

    expect(fn () => $registry->resolve('article', $target->getKey()))->toThrow(InvalidArgumentException::class);
});

it('diagnoses valid owner references alongside target resolver behavior', function (): void {
    config()->set('nvl-core.owners', ['post' => TestCommentTarget::class]);
    config()->set('nvl-comments.targets', ['article' => ['owner' => 'post', 'resolver' => TestCommentTargetResolver::class]]);
    $registry = app()->build(CommentTargetRegistry::class);
    $registry->register('article', TestCommentTargetResolver::class, 'post');
    app()->instance(CommentTargetRegistry::class, $registry);

    expect(app(CommentsDoctor::class)->inspect()['targets.ready'])->toBeTrue();
});
