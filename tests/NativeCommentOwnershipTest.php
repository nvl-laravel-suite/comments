<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Services\CommentModeration;
use Nvl\Comments\Services\CommentReactions;
use Nvl\Comments\Services\CommentStateReconciler;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Tests\Fixtures\OwnershipActor;
use Nvl\Comments\Tests\Fixtures\OwnershipComment;
use Nvl\Comments\Tests\Fixtures\OwnershipCommentTarget;
use Nvl\Comments\Tests\Fixtures\OwnershipCompany;
use Nvl\Comments\Tests\OwnershipTestCase;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;

uses(OwnershipTestCase::class);

it('fences native comments replies mutations and reconciliation to the admitted owner', function (): void {
    $records = [];
    foreach ([7, 8] as $ownerId) {
        app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail($ownerId), OwnershipActor::firstOrFail(), function () use ($ownerId, &$records): void {
            $target = app(OwnershipWriter::class)->create(OwnershipCommentTarget::class,
                fn (OwnershipAssignment $assignment) => OwnershipCommentTarget::query()->forceCreate([...$assignment->attributes(), 'name' => 'Target']));
            $comment = app(CommentWriter::class)->create($target, ['body' => 'Scoped comment'], OwnershipActor::firstOrFail());
            $records[$ownerId] = $comment;
            OwnershipComment::query()->whereKey($comment->id)->update(['reaction_count' => 9]);
            if ($ownerId === 8) {
                $foreign = $records[7];
                expect(OwnershipComment::query()->find($foreign->id))->toBeNull();
                expect(fn () => app(CommentWriter::class)->update($foreign, ['body' => 'Foreign update', 'expected_revision' => $foreign->revision], OwnershipActor::firstOrFail()))
                    ->toThrow(ModelNotFoundException::class);
                expect(fn () => app(CommentWriter::class)->create($target, ['body' => 'Foreign reply', 'parentId' => $foreign->id], OwnershipActor::firstOrFail()))
                    ->toThrow(ModelNotFoundException::class);
                $repair = app(CommentStateReconciler::class)->reconcile(null, 1, repair: true);
                expect($repair['scanned'])->toBe(1)->and($repair['repaired'])->toBe(1)
                    ->and($comment->fresh()->reaction_count)->toBe(0);
            }
        });
    }
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function () use ($records): void {
        expect($records[7]->fresh()->reaction_count)->toBe(9);
    });
});

it('inherits native comment child partitions from the canonical comment', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function (): void {
        $actor = OwnershipActor::firstOrFail();
        $target = app(OwnershipWriter::class)->create(OwnershipCommentTarget::class,
            fn (OwnershipAssignment $assignment) => OwnershipCommentTarget::query()->forceCreate([...$assignment->attributes(), 'name' => 'Target']));
        $comment = app(CommentWriter::class)->create($target, ['body' => 'Before'], $actor, CommentAudience::Public);
        app(CommentReactions::class)->add($comment, 'like', $actor);
        $report = app(CommentModeration::class)->report($comment, ['reason' => 'Review'], $actor);
        $comment->refresh();
        $updated = app(CommentWriter::class)->update($comment, ['body' => 'After', 'expected_revision' => $comment->revision], $actor);
        $scope = $comment->getRawOriginal('owner_scope_key');

        expect($scope)->toBeString()
            ->and($report->getRawOriginal('owner_scope_key'))->toBe($scope)
            ->and($updated->reactions()->sole()->getRawOriginal('owner_scope_key'))->toBe($scope)
            ->and($updated->revisions()->sole()->getRawOriginal('owner_scope_key'))->toBe($scope);
    });
});

it('partitions native idempotency receipts without replaying another owners comment', function (): void {
    config()->set('nvl-comments.idempotency.digest_key', 'owned-native-receipt-key');
    $comments = [];
    foreach ([7, 8] as $ownerId) {
        app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail($ownerId), OwnershipActor::firstOrFail(), function () use (&$comments): void {
            $target = app(OwnershipWriter::class)->create(OwnershipCommentTarget::class,
                fn (OwnershipAssignment $assignment) => OwnershipCommentTarget::query()->forceCreate([...$assignment->attributes(), 'name' => 'Target']));
            $comments[] = app(CommentWriter::class)->create($target, [
                'body' => 'Same receipt', 'idempotency_key' => '01900000-0000-7000-8000-000000000001',
            ], OwnershipActor::firstOrFail());
        });
    }

    expect($comments[0]->id)->not->toBe($comments[1]->id)
        ->and($comments[0]->getRawOriginal('owner_scope_key'))->not->toBe($comments[1]->getRawOriginal('owner_scope_key'));
});
