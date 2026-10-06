<?php

declare(strict_types=1);

use Nvl\Comments\Actions\AnonymizeCommentAction;
use Nvl\Comments\Actions\AttachCommentMediaAction;
use Nvl\Comments\Actions\CreateCommentAction;
use Nvl\Comments\Actions\ListCommentAttachmentsAction;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\AnonymizeCommentData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Http\Controllers\MemberCommentsController;
use Nvl\Comments\Http\Controllers\PublicCommentsController;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentProjectionFactory;
use Nvl\Comments\Services\CommentsDoctor;
use Nvl\Comments\Services\CommentStateReconciler;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Comments\Tests\StandaloneCommentsTestCase;

uses(StandaloneCommentsTestCase::class);

it('creates projects reconciles and anonymizes comments without a loaded Media provider', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Independent discussion']);
    $comment = app(CreateCommentAction::class)->execute($target, new CreateCommentData('Ordinary comment'), CommentActorData::system());
    $projection = app(CommentProjectionFactory::class)->publicComment($comment, $target);
    $attachments = app(ListCommentAttachmentsAction::class)->execute($comment, CommentActorData::anonymous(), CommentAudience::Public);
    $reconciliation = app(CommentStateReconciler::class)->reconcile(null, 100);
    $report = app(CommentsDoctor::class)->inspect();
    $anonymized = app(AnonymizeCommentAction::class)->execute($comment, new AnonymizeCommentData($comment->revision, 'Privacy request'), CommentActorData::system(), CommentAudience::Management);

    expect($projection->body)->toBe('Ordinary comment')
        ->and($projection->attachmentCount)->toBe(0)
        ->and($attachments)->toBeEmpty()
        ->and($reconciliation->healthy)->toBeTrue()
        ->and($report['attachments'])->toBeFalse()
        ->and($report['attachments.integration']['severity'])->toBe('info')
        ->and($anonymized->anonymized_at)->not->toBeNull()
        ->and(app(PublicCommentsController::class))->toBeInstanceOf(PublicCommentsController::class)
        ->and(app(MemberCommentsController::class))->toBeInstanceOf(MemberCommentsController::class)
        ->and(class_uses_recursive(Comment::class))->not->toContain('Nvl\\Media\\Traits\\InteractsWithMedia')
        ->and(class_implements(Comment::class))->not->toContain('Nvl\\Media\\Contracts\\HasMedia')
        ->and(app('router')->has('nvl.comments.attachments.asset'))->toBeFalse();
});

it('rejects explicitly requested attachments when Media discovery is disabled', function (): void {
    expect(fn () => app(AttachCommentMediaAction::class))->toThrow(InvalidArgumentException::class, 'requires the loaded provider');
    config()->set('comments.attachments.enabled', true);
    expect(fn () => app(CommentProjectionFactory::class))->toThrow(InvalidArgumentException::class, 'requires the loaded provider');
});
