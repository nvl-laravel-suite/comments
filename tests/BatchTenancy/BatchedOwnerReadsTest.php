<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nvl\Comments\Contracts\CommentBatchQueryScope;
use Nvl\Comments\Contracts\ListOwnerCommentSummariesContract;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentBatchQueryBuilder;
use Nvl\Comments\Tests\Fixtures\BatchCommentPolicy;
use Nvl\Comments\Tests\Fixtures\CommentBatchTenancyScenario;
use Nvl\Comments\Tests\Fixtures\TestCommentMentionResource;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

beforeEach(function (): void {
    $driver = getenv('NVL_C1_TEST_DRIVER');
    if (is_string($driver) && $driver !== '') {
        expect(DB::connection()->getDriverName())->toBe($driver);
    }
});

it('keeps the same domain query budget under real enabled tenancy', function (int $size): void {
    $scenario = CommentBatchTenancyScenario::install();
    $owners = [];
    for ($index = 0; $index < $size; $index++) {
        $owner = $scenario->owner($scenario::A);
        $owners[] = $owner;
        $scenario->run($scenario::A, function () use ($owner): void {
            Comment::query()->create([
                'tenant_id' => $owner->getAttribute('tenant_id'),
                'commentable_type' => $owner->getMorphClass(),
                'commentable_id' => (string) $owner->getKey(),
                'body' => 'Tenant visible',
                'status' => CommentStatus::Approved,
            ]);
        });
    }
    $scenario->run($scenario::A, function () use ($owners, $size): void {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = app(ListOwnerCommentSummariesContract::class)->execute($owners, CommentActorData::anonymous());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $domain = array_filter($queries, fn ($query) => str_contains($query['query'], 'comment_mention_test_resources') || str_contains($query['query'], 'nvl_comments'));
        expect(count($domain))->toBe(3)
            ->and(count($queries))->toBe(3)
            ->and($result->order)->toHaveCount($size);
        foreach ($owners as $owner) {
            $summary = $result->owners->{$owner->getMorphClass()}->{(string) $owner->getKey()};
            expect($summary->visibleCount)->toBe(1)->and($summary->latest)->toHaveCount(1);
        }
    });
})->with([1, 25, 100]);

it('rejects forged foreign owners and contains SQL policy ORs within tenant ownership', function (): void {
    $scenario = CommentBatchTenancyScenario::install();
    $owner = $scenario->owner($scenario::A);
    $foreign = $scenario->owner($scenario::B);
    foreach ([$scenario::A => $owner, $scenario::B => $foreign] as $tenant => $target) {
        $scenario->run($tenant, function () use ($target): void {
            Comment::query()->create(['tenant_id' => $target->getAttribute('tenant_id'), 'commentable_type' => $target->getMorphClass(), 'commentable_id' => (string) $target->getKey(), 'body' => 'Tenant', 'status' => CommentStatus::Approved]);
        });
    }
    $forged = clone $foreign;
    $forged->setAttribute('tenant_id', $scenario::A);
    $scenario->run($scenario::A, function () use ($owner, $forged): void {
        DB::enableQueryLog();
        DB::flushQueryLog();
        expect(fn () => app(ListOwnerCommentSummariesContract::class)->execute([$owner, $forged], CommentActorData::anonymous()))->toThrow(TenantBoundaryViolation::class);
        expect(array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'nvl_comments')))->toBe([]);
        DB::disableQueryLog();
    });
    $policy = new BatchCommentPolicy(broad: true);
    app()->instance(CommentBatchQueryScope::class, $policy);
    $scenario->run($scenario::A, function () use ($owner, $policy): void {
        $result = app(ListOwnerCommentSummariesContract::class)->execute([$owner], CommentActorData::anonymous());
        expect($result->owners->{$owner->getMorphClass()}->{(string) $owner->getKey()}->visibleCount)->toBe(1);
        $query = app(CommentBatchQueryBuilder::class)->withVisibleCount(TestCommentMentionResource::query()->withoutGlobalScopes(), CommentActorData::anonymous(), CommentAudience::Public, $policy);
        expect($query->get())->toHaveCount(1)->and($query->first()->getAttribute('nvl_visible_comment_count'))->toBe(1);
    });
});

it('requires explicit target ownership allowlisting before any comment SQL', function (): void {
    $scenario = CommentBatchTenancyScenario::install();
    $owner = $scenario->owner($scenario::A);
    config(['nvl-comments.tenancy.target_types' => []]);
    expect(fn () => $scenario->run($scenario::A, fn () => app(ListOwnerCommentSummariesContract::class)->execute([$owner], CommentActorData::anonymous())))
        ->toThrow(TenantBoundaryViolation::class, 'target_types');
});

it('rejects a drifted actual host builder under enabled tenancy before package SQL', function (): void {
    $scenario = CommentBatchTenancyScenario::install();
    $scenario->run($scenario::A, function (): void {
        $query = TestCommentMentionResource::query()->from('other_targets as comment_mention_test_resources');
        DB::enableQueryLog();
        DB::flushQueryLog();
        expect(fn () => app(CommentBatchQueryBuilder::class)->withVisibleCount($query, CommentActorData::anonymous(), CommentAudience::Public, new BatchCommentPolicy))
            ->toThrow(TenantBoundaryViolation::class);
        expect(DB::getQueryLog())->toBe([]);
        DB::disableQueryLog();
    });
});
