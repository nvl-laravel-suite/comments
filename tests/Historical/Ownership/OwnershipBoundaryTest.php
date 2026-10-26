<?php

declare(strict_types=1);
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Nvl\Comments\Actions\CreateCommentAction;
use Nvl\Comments\Actions\ListOwnerCommentSummariesAction;
use Nvl\Comments\Actions\ReportCommentAction;
use Nvl\Comments\Actions\SetCommentReactionAction;
use Nvl\Comments\Actions\UpdateCommentAction;
use Nvl\Comments\Contracts\CommentMentionResourceAuthorization;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Data\Mutations\ReportCommentData;
use Nvl\Comments\Data\Mutations\UpdateCommentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Comments\Enums\CommentMentionState;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Services\CommentModelRegistry;
use Nvl\Comments\Services\CommentRecordWriter;
use Nvl\Comments\Services\EloquentCommentMentionResourceResolver;
use Nvl\Comments\Tests\Fixtures\OwnershipActor;
use Nvl\Comments\Tests\Fixtures\OwnershipComment;
use Nvl\Comments\Tests\Fixtures\OwnershipCommentScenario;
use Nvl\Comments\Tests\Fixtures\OwnershipCommentTarget;
use Nvl\Comments\Tests\Fixtures\OwnershipCompany;
use Nvl\Comments\Tests\OwnershipTestCase;
use Nvl\Comments\ValueObjects\CommentMentionContext;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnershipTestCase::class);
it('inherits the exact configured target and isolates comments before querying', function (): void {
    $foreign = null;
    foreach ([7, 8] as $id) {
        app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail($id), OwnershipActor::firstOrFail(), function () use (&$foreign, $id): void {
            $target = app(OwnershipWriter::class)->create(OwnershipCommentTarget::class, fn (OwnershipAssignment $a) => OwnershipCommentTarget::query()->forceCreate([...$a->attributes(), 'name' => 'Target']));
            $comment = app(CreateCommentAction::class)->execute($target, CreateCommentData::from(['body' => 'Private body']), CommentActorData::fromAuthenticatable(OwnershipActor::firstOrFail()));
            expect($comment::class)->toBe(OwnershipComment::class)->and(CommentModelRegistry::query()->count())->toBe(1);
            if ($id === 7) {
                $foreign = $comment;
            } else {
                expect(CommentModelRegistry::query()->find($foreign->id))->toBeNull();
                expect(fn () => app(CreateCommentAction::class)->execute($target, CreateCommentData::from(['body' => 'Reply', 'parentId' => $foreign->id]), CommentActorData::fromAuthenticatable(OwnershipActor::firstOrFail())))->toThrow(ModelNotFoundException::class);
                $comment->commentable_id = $foreign->commentable_id;
                expect(fn () => $comment->saveQuietly())->toThrow(OwnershipViolation::class);
            }
        });
    }
});

it('preserves ownership on reports revisions reactions and event envelopes', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function (): void {
        $comment = OwnershipCommentScenario::comment();
        $actor = OwnershipCommentScenario::actor();
        $report = app(ReportCommentAction::class)->execute($comment, ReportCommentData::from(['reason' => 'spam']), $actor);
        app(SetCommentReactionAction::class)->execute($comment, 'like', true, $actor);
        $updated = app(UpdateCommentAction::class)->execute($comment, UpdateCommentData::from(['body' => 'Edited', 'expectedRevision' => 1]), $actor);
        expect($report->getRawOriginal('owner_scope_key'))->toBe($comment->getRawOriginal('owner_scope_key'))
            ->and($updated->revisions()->count())->toBe(1)->and($updated->reactions()->count())->toBe(1);
        $event = new CommentChanged($comment->id, $comment->commentable_type, $comment->commentable_id, CommentChangeOperation::Created, 1, $actor);
        expect($event)->toBeInstanceOf(OwnershipQueuedJob::class)
            ->and($event->ownershipJobEnvelope()->data['mode'])->toBe('owner');
    });
});

it('rejects a different target in the same owner hierarchy and raw child partition forgery', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function (): void {
        $root = OwnershipCommentScenario::comment();
        $other = OwnershipCommentScenario::target();
        expect(fn () => CommentModelRegistry::create(['commentable_type' => $other->getMorphClass(), 'commentable_id' => $other->id, 'parent_id' => $root->id, 'root_id' => $root->id, 'body' => 'Forged']))->toThrow(OwnershipViolation::class);
        $child = CommentRecordWriter::create(CommentRevision::class, ['comment_id' => $root->id, 'revision' => 1, 'body' => 'Snapshot', 'format' => 'plain']);
        expect(fn () => DB::table($child->getTable())->where('id', $child->id)->update(['owner_scope_key' => 'owner:'.str_repeat('a', 64)]))->toThrow(QueryException::class);
    });
});

it('keeps admitted batch query growth bounded for one and twenty five targets', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function (): void {
        $targets = [];
        for ($i = 0; $i < 25; $i++) {
            $target = OwnershipCommentScenario::target();
            $targets[] = $target;
            OwnershipCommentScenario::comment($target);
        }
        $measure = function (array $owners): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $result = app(ListOwnerCommentSummariesAction::class)->execute($owners, CommentActorData::anonymous());
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();
            expect($result->order)->toHaveCount(count($owners));

            return $n;
        };
        $one = $measure([$targets[0]]);
        $many = $measure($targets);
        expect($many)->toBeLessThanOrEqual($one + 2);
    });
});

it('partitions idempotency receipts without replaying a foreign owner', function (): void {
    $ids = [];
    foreach ([7, 8] as $id) {
        app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail($id), OwnershipActor::firstOrFail(), function () use (&$ids): void {
            $target = OwnershipCommentScenario::target();
            $comment = app(CreateCommentAction::class)->execute($target, CreateCommentData::from(['body' => 'Receipt', 'idempotencyKey' => '01900000-0000-7000-8000-000000000001']), OwnershipCommentScenario::actor());
            $ids[] = $comment->id;
        });
    } expect(array_unique($ids))->toHaveCount(2);
});

it('scopes live mention resolution before labels and search pagination', function (): void {
    $foreign = null;
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(8), OwnershipActor::firstOrFail(), function () use (&$foreign): void {
        $foreign = OwnershipCommentScenario::target();
    });
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function () use ($foreign): void {
        $target = OwnershipCommentScenario::target();
        $authorization = new class implements CommentMentionResourceAuthorization
        {
            public function scope(Builder $query, CommentMentionContext $context): void {}
        };
        $resolver = new EloquentCommentMentionResourceResolver(OwnershipCommentTarget::class, ['name'], [], 'name', $authorization, boundary: app(OwnershipBoundary::class));
        $context = new CommentMentionContext($target, CommentActorData::anonymous(), CommentAudience::Public);
        $rows = $resolver->resolve($context, [$foreign->id, $target->id]);
        expect($rows->pluck('id')->all())->toBe([$foreign->id, $target->id])->and($rows[0]->label)->toBeNull()->and($rows[0]->state)->toBe(CommentMentionState::Missing)->and($rows[1]->label)->toBe('Target');
        expect($resolver->suggest($context, 'Target', 25)->pluck('id')->all())->toBe([$target->id]);
    });
});
