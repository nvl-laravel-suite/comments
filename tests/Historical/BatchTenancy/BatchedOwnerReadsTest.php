<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
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

it('retains adopted native live-owner guards and caller ORs in tenant host counts', function (string $fixture, bool $direct, string $mode): void {
    $scenario = CommentBatchTenancyScenario::install();
    $live = $fixture === 'custom' ? $scenario->liveOwner($scenario::A) : $scenario->owner($scenario::A);
    $deleted = $fixture === 'custom' ? $scenario->liveOwner($scenario::A) : $scenario->owner($scenario::A);
    $scenario->run($scenario::A, function () use ($live, $deleted, $direct, $mode): void {
        $live->update(['name' => 'Live']);
        $deleted->update(['name' => 'Deleted']);
        foreach ([$live, $deleted] as $owner) {
            Comment::query()->create(['tenant_id' => $owner->getAttribute('tenant_id'), 'commentable_type' => $owner->getMorphClass(), 'commentable_id' => (string) $owner->getKey(), 'body' => 'Public', 'status' => CommentStatus::Approved]);
        }
        $deleted->delete();
        $query = $live->newQuery()->select(['id', 'name'])->selectRaw('? as host_marker', ['selected']);
        if ($mode === 'with-trashed') {
            $query->withTrashed();
        } elseif ($mode !== 'retained') {
            $query->withoutGlobalScopes();
        }
        if ($mode === 'without-global-scopes-or') {
            $query->whereKey($deleted->getKey())->orWhere($live->getQualifiedKeyName(), $live->getKey());
        }
        $policy = app(CommentBatchQueryScope::class);
        $counted = $direct
            ? app(CommentBatchQueryBuilder::class)->withVisibleCount($query, CommentActorData::anonymous(), CommentAudience::Public, $policy)
            : $query->withNvlVisibleCommentCount(CommentActorData::anonymous(), CommentAudience::Public, $policy);

        expect($counted->get()->map(static fn (Model $owner): array => $owner->getAttributes())->all())
            ->toBe([['id' => $live->getKey(), 'name' => 'Live', 'host_marker' => 'selected', 'nvl_visible_comment_count' => 1]]);
    });
})->with([
    'native deletion direct helper' => ['native', true],
    'custom deletion direct helper' => ['custom', true],
    'custom deletion public scope' => ['custom', false],
])->with(['retained', 'with-trashed', 'without-global-scopes', 'without-global-scopes-or']);

it('contains pre-existing host OR predicates within the active tenant', function (string $fixture, bool $direct): void {
    $scenario = CommentBatchTenancyScenario::install();
    $live = $fixture === 'custom' ? $scenario->liveOwner($scenario::A) : $scenario->owner($scenario::A);
    $foreign = $fixture === 'custom' ? $scenario->liveOwner($scenario::B) : $scenario->owner($scenario::B);
    foreach ([$scenario::A => $live, $scenario::B => $foreign] as $tenant => $owner) {
        $scenario->run($tenant, function () use ($owner): void {
            Comment::query()->create(['tenant_id' => $owner->getAttribute('tenant_id'), 'commentable_type' => $owner->getMorphClass(), 'commentable_id' => (string) $owner->getKey(), 'body' => 'Public', 'status' => CommentStatus::Approved]);
        });
    }
    $scenario->run($scenario::A, function () use ($live, $foreign, $direct): void {
        $query = $live->newQuery()->withoutGlobalScopes()->select(['id', 'name'])
            ->whereKey($foreign->getKey())->orWhere($live->getQualifiedKeyName(), $live->getKey());
        $policy = app(CommentBatchQueryScope::class);
        $counted = $direct
            ? app(CommentBatchQueryBuilder::class)->withVisibleCount($query, CommentActorData::anonymous(), CommentAudience::Public, $policy)
            : $query->withNvlVisibleCommentCount(CommentActorData::anonymous(), CommentAudience::Public, $policy);

        expect($counted->get()->map(static fn (Model $owner): array => $owner->getAttributes())->all())
            ->toBe([['id' => $live->getKey(), 'name' => 'Owner', 'nvl_visible_comment_count' => 1]]);
    });
})->with([
    'native deletion direct helper' => ['native', true],
    'custom deletion direct helper' => ['custom', true],
    'custom deletion public scope' => ['custom', false],
]);

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
