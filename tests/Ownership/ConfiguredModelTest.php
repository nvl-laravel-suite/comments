<?php

declare(strict_types=1);
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentModelRegistry;
use Nvl\Comments\Tests\Fixtures\OwnershipActor;
use Nvl\Comments\Tests\Fixtures\OwnershipComment;
use Nvl\Comments\Tests\Fixtures\OwnershipCommentScenario;
use Nvl\Comments\Tests\Fixtures\OwnershipCompany;
use Nvl\Comments\Tests\OwnershipTestCase;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnershipTestCase::class);
it('uses exact configured factories and denies stale base classes or disabled runtime', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function (): void {
        $target = OwnershipCommentScenario::target();
        expect(OwnershipComment::factory()->forOwner($target)->make()::class)->toBe(OwnershipComment::class);
        expect(fn () => Comment::query()->get())->toThrow(OwnershipViolation::class);
        $comment = OwnershipCommentScenario::comment($target);
        expect((new Comment)->resolveRouteBinding($comment->id)::class)->toBe(OwnershipComment::class);
        expect((new Comment)->resolveSoftDeletableRouteBinding($comment->id)::class)->toBe(OwnershipComment::class);
        expect($comment->body)->toBe('configured:Private comment');
        config(['nvl-ownership.enabled' => false]);
        expect(fn () => CommentModelRegistry::query()->get())->toThrow(OwnershipViolation::class);
        expect(fn () => $comment->saveQuietly())->toThrow(OwnershipSchemaNotReady::class);
    });
});
