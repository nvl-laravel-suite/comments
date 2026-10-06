<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Comments\Actions\FindLatestTargetCommentAction;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentBatchQueryScope;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Contracts\ListOwnerCommentSummariesContract;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Queries\CommentSelectorData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Comments\Exceptions\CommentBatchReadException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Providers\CommentsServiceProvider;
use Nvl\Comments\Services\CommentBatchQueryBuilder;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\ConfiguredCommentAuthorization;
use Nvl\Comments\Support\CommentBatchOwnerAdmission;
use Nvl\Comments\Tests\Fixtures\BatchCommentPolicy;
use Nvl\Comments\Tests\Fixtures\BatchSoftDeletingCommentOwner;
use Nvl\Comments\Tests\Fixtures\TestCommentMentionResource;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Comments\Tests\Fixtures\TestConfiguredCommentTarget;
use Nvl\Comments\Tests\Fixtures\TestIntegerCommentTarget;
use Nvl\Comments\Tests\Fixtures\TestStringCommentTarget;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

beforeEach(function (): void {
    $driver = getenv('NVL_C1_TEST_DRIVER');
    if (is_string($driver) && $driver !== '') {
        expect(DB::connection()->getDriverName())->toBe($driver);
    }
    $this->batchDefaultConnection = DB::getDefaultConnection();
    $this->batchMorphMap = Relation::morphMap();
    $this->batchRequiresMorphMap = Relation::requiresMorphMap();
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
});

afterEach(function (): void {
    DB::setDefaultConnection($this->batchDefaultConnection);
    Relation::morphMap($this->batchMorphMap, false);
    Relation::requireMorphMap($this->batchRequiresMorphMap);
});

it('returns an empty object map without querying storage', function (): void {
    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = app(ListOwnerCommentSummariesContract::class)->execute([], CommentActorData::anonymous());
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

    expect($queries)->toBe([])
        ->and($json->owners)->toBeInstanceOf(stdClass::class)
        ->and(get_object_vars($json->owners))->toBe([])
        ->and($json->order)->toBe([]);
});

it('bounds comment queries and latest transfer independently of owner count', function (int $count, int $latestLimit): void {
    $owners = [];
    for ($index = 0; $index < $count; $index++) {
        $owner = TestCommentTarget::query()->create(['name' => 'Batch owner '.$index]);
        $owners[] = $owner;
        for ($commentIndex = 0; $commentIndex < 12; $commentIndex++) {
            Comment::query()->create([
                'commentable_type' => $owner->getMorphClass(),
                'commentable_id' => (string) $owner->getKey(),
                'actor_type' => 'member',
                'actor_id' => 'author',
                'body' => 'Visible '.$commentIndex,
                'status' => CommentStatus::Approved,
                'visibility' => CommentVisibility::Public,
            ]);
        }
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = app(ListOwnerCommentSummariesContract::class)->execute($owners, CommentActorData::anonymous(), latestLimit: $latestLimit);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

    $domainQueries = array_filter($queries, fn ($query) => str_contains($query['query'], 'comment_test_targets') || str_contains($query['query'], 'nvl_comments'));
    expect(count($domainQueries))->toBe(3)
        ->and(count($queries) - count($domainQueries))->toBe(1)
        ->and($json->order)->toHaveCount($count);
    foreach ($owners as $owner) {
        $summary = $json->owners->{$owner->getMorphClass()}->{(string) $owner->getKey()};
        expect($summary->visibleCount)->toBe(12)
            ->and($summary->latest)->toHaveCount($latestLimit);
        foreach ($summary->latest as $latest) {
            expect(get_object_vars($latest))->not->toHaveKeys(['actor_id', 'actorId', 'metadata', 'document', 'attachments']);
        }
    }
    $latestQuery = array_values(array_filter($queries, fn ($query) => str_contains(strtolower($query['query']), 'row_number()')));
    expect(DB::select($latestQuery[0]['query'], $latestQuery[0]['bindings']))->toHaveCount($count * $latestLimit);
    DB::enableQueryLog();
    DB::flushQueryLog();
    app(ListOwnerCommentSummariesContract::class)->execute($owners, CommentActorData::anonymous(), latestLimit: $latestLimit);
    expect(DB::getQueryLog())->toHaveCount(3);
    DB::disableQueryLog();
})->with([[1, 3], [25, 3], [100, 10]]);

it('rejects invalid latest bounds before storage queries', function (int $limit): void {
    $reader = app(ListOwnerCommentSummariesContract::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => $reader->execute([], CommentActorData::anonymous(), CommentAudience::Public, $limit))
        ->toThrow(InvalidArgumentException::class);
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
})->with([0, 11]);

it('matches the admitted audience predicate for both counts and latest rows', function (string $viewer, int $expectedCount): void {
    $owner = TestCommentTarget::query()->create(['name' => 'Audience owner']);
    $rows = [
        ['status' => CommentStatus::Approved, 'visibility' => CommentVisibility::Public, 'actor_id' => 'other'],
        ['status' => CommentStatus::Pending, 'visibility' => CommentVisibility::Public, 'actor_id' => 'viewer'],
        ['status' => CommentStatus::Rejected, 'visibility' => CommentVisibility::Public, 'actor_id' => 'viewer'],
        ['status' => CommentStatus::Hidden, 'visibility' => CommentVisibility::Public, 'actor_id' => 'viewer'],
        ['status' => CommentStatus::Hidden, 'visibility' => CommentVisibility::Private, 'actor_id' => 'viewer'],
        ['status' => CommentStatus::Approved, 'visibility' => CommentVisibility::Private, 'actor_id' => 'other'],
    ];
    foreach ($rows as $index => $attributes) {
        Comment::query()->create([
            ...$attributes,
            'commentable_type' => $owner->getMorphClass(),
            'commentable_id' => (string) $owner->getKey(),
            'actor_type' => 'member',
            'body' => 'Audience '.$index,
            'metadata' => ['secret' => 'private metadata'],
        ]);
    }
    $deleted = Comment::query()->create([
        'commentable_type' => $owner->getMorphClass(),
        'commentable_id' => (string) $owner->getKey(),
        'actor_type' => 'member',
        'actor_id' => 'viewer',
        'body' => 'Deleted',
        'status' => CommentStatus::Approved,
        'visibility' => CommentVisibility::Public,
    ]);
    $deleted->delete();
    $actor = match ($viewer) {
        'member' => new CommentActorData('member', 'viewer'),
        'system' => CommentActorData::system(),
        default => CommentActorData::anonymous(),
    };
    $audience = match ($viewer) {
        'member' => CommentAudience::Member,
        'system' => CommentAudience::Management,
        default => CommentAudience::Public,
    };
    $expectedIds = app(CommentReadService::class)->query($owner, $actor, $audience, withTrashed: false)
        ->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();
    $expectedLatest = app(FindLatestTargetCommentAction::class)->execute($owner, $actor, new CommentSelectorData, $audience);
    $result = app(ListOwnerCommentSummariesContract::class)->execute([$owner], $actor, $audience, latestLimit: 10);
    $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $summary = $json->owners->{$owner->getMorphClass()}->{(string) $owner->getKey()};

    expect($summary->visibleCount)->toBe($expectedCount)
        ->and(array_column($summary->latest, 'id'))->toBe($expectedIds)
        ->and($summary->latest[0]->id)->toBe($expectedLatest->id)
        ->and($summary->latest[0]->body)->toBe($expectedLatest->body)
        ->and($summary->latest[0]->format)->toBe($expectedLatest->format->value);
})->with(['public' => ['public', 1], 'member' => ['member', 4], 'system' => ['system', 6]]);

it('keeps equal keys on different native morph types distinct and retains requested order', function (): void {
    Schema::create('comment_string_targets', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->timestamps();
    });
    $first = TestStringCommentTarget::query()->create(['id' => '0', 'name' => 'First']);
    $second = TestCommentMentionResource::query()->create([
        'id' => '0', 'tenant_id' => 'host-one', 'name' => 'Second', 'registration_number' => 'R0',
    ]);
    Relation::morphMap(['host.second:v2' => TestCommentMentionResource::class]);
    foreach ([$first, $first, $second] as $owner) {
        Comment::query()->create([
            'commentable_type' => $owner->getMorphClass(),
            'commentable_id' => (string) $owner->getKey(),
            'body' => 'Exact pair',
            'status' => CommentStatus::Approved,
            'visibility' => CommentVisibility::Public,
        ]);
    }
    $result = app(ListOwnerCommentSummariesContract::class)->execute([$second, $first, clone $first], CommentActorData::anonymous());
    $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

    expect($json->owners->{'host.second:v2'}->{'0'}->visibleCount)->toBe(1)
        ->and($json->owners->{TestStringCommentTarget::class}->{'0'}->visibleCount)->toBe(2)
        ->and($json->order)->toEqual([
            (object) ['type' => 'host.second:v2', 'id' => '0'],
            (object) ['type' => TestStringCommentTarget::class, 'id' => '0'],
        ]);
});

it('denies management reads to an ordinary actor before comment SQL', function (): void {
    $owner = TestCommentTarget::query()->create(['name' => 'Denied']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => app(ListOwnerCommentSummariesContract::class)->execute([$owner], new CommentActorData('member', 'viewer'), CommentAudience::Management))
        ->toThrow(AuthorizationException::class);
    expect(DB::getQueryLog())->toHaveCount(1);
    DB::disableQueryLog();
});

it('denies management host counts and mismatched active query scopes', function (): void {
    $owner = TestCommentTarget::query()->create(['name' => 'Host denied']);
    expect(fn () => TestCommentTarget::query()->withNvlVisibleCommentCount(CommentActorData::anonymous(), CommentAudience::Management))->toThrow(AuthorizationException::class);
    app()->instance(CommentQueryScope::class, new BatchCommentPolicy);
    expect(fn () => app(ListOwnerCommentSummariesContract::class)->execute([$owner], CommentActorData::anonymous()))->toThrow(CommentBatchReadException::class);
    expect(fn () => TestCommentTarget::query()->withNvlVisibleCommentCount(CommentActorData::anonymous()))->toThrow(CommentBatchReadException::class);
});

it('fails with a package exception for unsupported storage drivers before SQL', function (): void {
    $default = DB::getDefaultConnection();
    config(['database.connections.batch_unsupported' => ['driver' => 'sqlsrv', 'host' => '127.0.0.1', 'database' => 'never_connect', 'username' => 'unused', 'password' => '', 'prefix' => '']]);
    DB::setDefaultConnection('batch_unsupported');
    expect(fn () => app(CommentBatchQueryBuilder::class)->batch(OwnerBatch::fromModels([]), CommentActorData::anonymous(), CommentAudience::Public, new ConfiguredCommentAuthorization))
        ->toThrow(CommentBatchReadException::class, 'window-query');
    DB::setDefaultConnection($default);
});

it('rejects absent deleted scoped or connection-drifted owners before comment SQL', function (string $kind): void {
    $owner = TestCommentMentionResource::query()->create(['id' => '007', 'tenant_id' => 'host', 'name' => 'Visible', 'registration_number' => 'R']);
    match ($kind) {
        'deleted' => $owner->delete(),
        'missing' => TestCommentMentionResource::query()->whereKey($owner->getKey())->forceDelete(),
        'scoped' => TestCommentMentionResource::addGlobalScope('batch-hidden', fn ($query) => $query->where('name', 'Hidden')),
        'connection' => $owner->setConnection('foreign'),
        'table' => $owner->setTable('foreign_table'),
    };
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => app(ListOwnerCommentSummariesContract::class)->execute([$owner], CommentActorData::anonymous()))
        ->toThrow(TenantBoundaryViolation::class);
    expect(array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'nvl_comments')))->toBe([]);
    DB::disableQueryLog();
})->with(['deleted', 'missing', 'scoped', 'connection', 'table']);

it('fails closed for late unsupported policies but retains no-query empty results', function (): void {
    $owner = TestCommentTarget::query()->create(['name' => 'Policy owner']);
    $policy = new class implements CommentAuthorization
    {
        public function allows(CommentAbility $ability, CommentActorData $actor, ?Comment $comment = null, ?Model $target = null, CommentAudience $audience = CommentAudience::Public, array $context = []): bool
        {
            return false;
        }
    };
    app()->instance(CommentAuthorization::class, $policy);
    $reader = app(ListOwnerCommentSummariesContract::class);
    expect($reader->execute([], CommentActorData::anonymous())->order)->toBe([]);
    expect(fn () => $reader->execute([$owner], CommentActorData::anonymous()))
        ->toThrow(CommentBatchReadException::class, 'CommentBatchQueryScope');
});

it('bounds dense latest rows in storage and breaks created-at ties by descending id', function (): void {
    $owner = TestCommentTarget::query()->create(['name' => 'Dense owner']);
    for ($index = 0; $index < 75; $index++) {
        $comment = new Comment(['commentable_type' => $owner->getMorphClass(), 'commentable_id' => (string) $owner->getKey(), 'body' => 'Dense', 'status' => CommentStatus::Approved, 'visibility' => CommentVisibility::Public]);
        $comment->setAttribute('id', sprintf('00000000-0000-4000-8000-%012d', $index));
        $comment->setAttribute('created_at', '2026-01-01 00:00:00');
        $comment->save();
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = app(ListOwnerCommentSummariesContract::class)->execute([$owner], CommentActorData::anonymous(), latestLimit: 10);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $summary = $result->owners->{$owner->getMorphClass()}->{(string) $owner->getKey()};
    expect($summary->visibleCount)->toBe(75)
        ->and($summary->latest)->toHaveCount(10)
        ->and($summary->latest[0]->id)->toBe('00000000-0000-4000-8000-000000000074');
    $latestQuery = array_values(array_filter($queries, fn ($query) => str_contains(strtolower($query['query']), 'row_number()')));
    expect($latestQuery)->toHaveCount(1)
        ->and(DB::select($latestQuery[0]['query'], $latestQuery[0]['bindings']))->toHaveCount(10);
});

it('adds a visible host count while retaining host selection and scope', function (): void {
    $owner = TestCommentTarget::query()->create(['name' => 'Visible']);
    TestCommentTarget::query()->create(['name' => 'Other']);
    Comment::query()->create(['commentable_type' => $owner->getMorphClass(), 'commentable_id' => (string) $owner->getKey(), 'body' => 'Public', 'status' => CommentStatus::Approved, 'visibility' => CommentVisibility::Public]);
    Comment::query()->create(['commentable_type' => $owner->getMorphClass(), 'commentable_id' => (string) $owner->getKey(), 'body' => 'Private', 'status' => CommentStatus::Hidden, 'visibility' => CommentVisibility::Private]);
    TestCommentTarget::addGlobalScope('visible-name', fn ($query) => $query->where('name', 'Visible'));
    $rows = TestCommentTarget::query()->select('id', 'name')->withNvlVisibleCommentCount(CommentActorData::anonymous())->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->name)->toBe('Visible')
        ->and((int) $rows[0]->getAttribute('nvl_visible_comment_count'))->toBe(1);
});

it('keeps deleted host owners outside visible counts after global scopes are removed', function (): void {
    $owner = TestCommentMentionResource::query()->create(['id' => 'deleted-owner', 'tenant_id' => 'host', 'name' => 'Deleted', 'registration_number' => 'deleted']);
    Comment::query()->create(['commentable_type' => $owner->getMorphClass(), 'commentable_id' => (string) $owner->getKey(), 'body' => 'Public', 'status' => CommentStatus::Approved, 'visibility' => CommentVisibility::Public]);
    $owner->delete();
    $query = $owner->newQuery()->withoutGlobalScopes();
    app(CommentBatchQueryBuilder::class)->withVisibleCount($query, CommentActorData::anonymous(), CommentAudience::Public, app(CommentBatchQueryScope::class));

    expect($query->pluck('id')->all())->toBe([]);
});

it('retains the native custom live-owner guard and caller OR in host counts', function (bool $direct, string $mode): void {
    Schema::create((new BatchSoftDeletingCommentOwner)->getTable(), static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->softDeletes(BatchSoftDeletingCommentOwner::DELETED_AT);
        $table->timestamps();
    });
    $live = BatchSoftDeletingCommentOwner::query()->create(['name' => 'Live']);
    $deleted = BatchSoftDeletingCommentOwner::query()->create(['name' => 'Deleted']);
    foreach ([$live, $deleted] as $owner) {
        Comment::query()->create(['commentable_type' => $owner->getMorphClass(), 'commentable_id' => (string) $owner->getKey(), 'body' => 'Public', 'status' => CommentStatus::Approved, 'visibility' => CommentVisibility::Public]);
    }
    $deleted->delete();
    $query = BatchSoftDeletingCommentOwner::query()->select(['id', 'name'])->selectRaw('? as host_marker', ['selected']);
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

    expect($counted->get()->map(static fn (BatchSoftDeletingCommentOwner $owner): array => $owner->getAttributes())->all())
        ->toBe([['id' => $live->getKey(), 'name' => 'Live', 'host_marker' => 'selected', 'nvl_visible_comment_count' => 1]]);
})->with(['direct helper' => true, 'public scope' => false])->with(['retained', 'with-trashed', 'without-global-scopes', 'without-global-scopes-or']);

it('preserves unrelated removable host scopes and OR selections on non-soft-deleting owners', function (bool $direct): void {
    $live = TestCommentTarget::query()->create(['name' => 'Visible']);
    $other = TestCommentTarget::query()->create(['name' => 'Other']);
    foreach ([$live, $other] as $owner) {
        Comment::query()->create(['commentable_type' => $owner->getMorphClass(), 'commentable_id' => (string) $owner->getKey(), 'body' => 'Public', 'status' => CommentStatus::Approved, 'visibility' => CommentVisibility::Public]);
    }
    $query = TestCommentTarget::query()->withGlobalScope('batch-visible-name', static fn (Builder $query) => $query->where('name', 'Visible'));
    expect((clone $query)->pluck('id')->all())->toBe([$live->getKey()]);
    $query->withoutGlobalScope('batch-visible-name')->select(['id', 'name'])
        ->whereKey($other->getKey())->orWhere($live->getQualifiedKeyName(), $live->getKey());
    $policy = app(CommentBatchQueryScope::class);
    $counted = $direct
        ? app(CommentBatchQueryBuilder::class)->withVisibleCount($query, CommentActorData::anonymous(), CommentAudience::Public, $policy)
        : $query->withNvlVisibleCommentCount(CommentActorData::anonymous(), CommentAudience::Public, $policy);
    $rows = $counted->get()->keyBy('name');

    expect($rows->keys()->sort()->values()->all())->toBe(['Other', 'Visible']);
    foreach ([$live, $other] as $owner) {
        expect($rows[$owner->name]->getAttributes())->toBe(['id' => $owner->getKey(), 'name' => $owner->name, 'nvl_visible_comment_count' => 1]);
    }
})->with(['direct helper' => true, 'public scope' => false]);

it('rejects actual host builder connection or table drift before storage probes', function (string $kind): void {
    config(['database.connections.batch_foreign' => config('database.connections.testing')]);
    $query = TestCommentTarget::query();
    if ($kind === 'connection') {
        $query->setQuery(DB::connection('batch_foreign')->table($query->getModel()->getTable()));
    } else {
        $query->from('other_targets as '.$query->getModel()->getTable());
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => $query->withNvlVisibleCommentCount(CommentActorData::anonymous()))->toThrow(TenantBoundaryViolation::class);
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
})->with(['connection', 'table']);

it('rejects removal of a declared owner connection even when the effective connection is unchanged', function (): void {
    config(['database.connections.comments' => config('database.connections.testing'), 'nvl-comments.connection' => 'comments']);
    DB::setDefaultConnection('comments');
    $owner = (new TestConfiguredCommentTarget)->setConnection(null);
    expect(fn () => app(CommentBatchOwnerAdmission::class)->assertPrototype($owner))->toThrow(TenantBoundaryViolation::class);
});

it('omits anonymized body and format from lightweight latest rows', function (): void {
    $owner = TestCommentTarget::query()->create(['name' => 'Anonymized target']);
    Comment::query()->create([
        'commentable_type' => $owner->getMorphClass(),
        'commentable_id' => (string) $owner->getKey(),
        'body' => 'Prior private text',
        'status' => CommentStatus::Approved,
        'visibility' => CommentVisibility::Public,
        'anonymized_at' => now(),
    ]);
    $result = app(ListOwnerCommentSummariesContract::class)->execute([$owner], CommentActorData::anonymous());
    $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $latest = $json->owners->{$owner->getMorphClass()}->{(string) $owner->getKey()}->latest[0];

    expect(get_object_vars($latest))->not->toHaveKeys(['body', 'format']);
});

it('derives custom SQL scope only from the same active instance or an explicit adapter', function (string $binding): void {
    $owner = TestCommentTarget::query()->create(['name' => 'Canonical']);
    $owner->setAttribute('name', 'Forged');
    $policy = new BatchCommentPolicy;
    app()->instance(CommentAuthorization::class, $policy);
    app()->instance(CommentQueryScope::class, $binding === 'same' ? $policy : new BatchCommentPolicy);
    if ($binding === 'explicit') {
        app()->instance(CommentBatchQueryScope::class, $policy);
    }
    $reader = app(ListOwnerCommentSummariesContract::class);
    if ($binding === 'different') {
        expect(fn () => $reader->execute([$owner], CommentActorData::anonymous()))->toThrow(CommentBatchReadException::class);

        return;
    }
    $summary = $reader->execute([$owner], CommentActorData::anonymous())->owners->{$owner->getMorphClass()}->{(string) $owner->getKey()};
    expect($summary->visibleCount)->toBe(0)
        ->and($summary->latest)->toBe([])
        ->and($policy->admitted[0]->name)->toBe('Canonical');
})->with(['same', 'different', 'explicit']);

it('contains policy OR clauses inside mandatory exact owner and deletion constraints', function (): void {
    $owners = [TestCommentTarget::query()->create(['name' => 'Requested']), TestCommentTarget::query()->create(['name' => 'Foreign'])];
    foreach ($owners as $owner) {
        Comment::query()->create(['commentable_type' => $owner->getMorphClass(), 'commentable_id' => (string) $owner->getKey(), 'body' => 'Allowed', 'status' => CommentStatus::Approved]);
        $deleted = Comment::query()->create(['commentable_type' => $owner->getMorphClass(), 'commentable_id' => (string) $owner->getKey(), 'body' => 'Deleted', 'status' => CommentStatus::Approved]);
        $deleted->delete();
    }
    app()->instance(CommentBatchQueryScope::class, new BatchCommentPolicy(broad: true));
    $summary = app(ListOwnerCommentSummariesContract::class)->execute([$owners[0]], CommentActorData::anonymous())->owners->{$owners[0]->getMorphClass()}->{(string) $owners[0]->getKey()};
    expect($summary->visibleCount)->toBe(1)
        ->and($summary->latest)->toHaveCount(1)
        ->and(TestCommentTarget::query()->whereKey($owners[0]->getKey())->withNvlVisibleCommentCount(CommentActorData::anonymous())->first()->getAttribute('nvl_visible_comment_count'))->toBe(1);
});

it('rejects oversized or ambiguous input before any SQL', function (string $kind): void {
    $owner = TestCommentTarget::query()->create(['name' => 'Input']);
    $changed = clone $owner;
    $changed->setAttribute('name', 'Conflict');
    $owners = match ($kind) {
        'oversize' => array_fill(0, 101, $owner),
        'ambiguous' => [$owner, $changed],
        'unsaved' => [new TestCommentTarget],
    };
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => app(ListOwnerCommentSummariesContract::class)->execute($owners, CommentActorData::anonymous()))->toThrow(InvalidArgumentException::class);
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
})->with(['oversize', 'ambiguous', 'unsaved']);

it('uses exact textual keys for host integer correlation and legacy mismatch exclusion', function (): void {
    Schema::create('comment_integer_targets', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    $owner = TestIntegerCommentTarget::query()->create(['name' => 'Integer']);
    foreach ([(string) $owner->getKey(), '0'.$owner->getKey()] as $id) {
        Comment::query()->create(['commentable_type' => $owner->getMorphClass(), 'commentable_id' => $id, 'body' => 'Exact key', 'status' => CommentStatus::Approved]);
    }
    expect($owner->newQuery()->withNvlVisibleCommentCount(CommentActorData::anonymous())->first()->getAttribute('nvl_visible_comment_count'))->toBe(1);
    expect(app(ListOwnerCommentSummariesContract::class)->execute([$owner], CommentActorData::anonymous())->owners->{$owner->getMorphClass()}->{(string) $owner->getKey()}->visibleCount)->toBe(1);
});

it('compiles ranked latest and byte-exact host correlation for each supported driver', function (string $driver): void {
    $owner = TestCommentTarget::query()->create(['name' => 'Compile']);
    config(['database.connections.batch_compile' => ['driver' => $driver, 'host' => '127.0.0.1', 'database' => 'never_connect', 'username' => 'unused', 'password' => '', 'prefix' => '']]);
    DB::setDefaultConnection('batch_compile');
    app()->instance(TenantBoundary::class, new class implements TenantBoundary
    {
        public function query(Builder $query, string $resource): Builder
        {
            return $query;
        }

        public function assertRecord(Model $record, string $resource): void {}

        public function attributes(string $resource): array
        {
            return [];
        }

        public function key(string $resource, string $identity): string
        {
            return $identity;
        }
    });
    $builder = app(CommentBatchQueryBuilder::class);
    $ownerQuery = TestCommentTarget::query();
    CommentBatchOwnerAdmission::exact($ownerQuery, $ownerQuery->getModel()->getQualifiedKeyName(), (string) $owner->getKey());
    $wrappedKey = $ownerQuery->getConnection()->getQueryGrammar()->wrap($ownerQuery->getModel()->getQualifiedKeyName());
    $castType = in_array($driver, ['mysql', 'mariadb'], true) ? 'CHAR' : 'TEXT';
    expect($ownerQuery->toSql())->toContain("CAST({$wrappedKey} AS {$castType})");
    $policy = new ConfiguredCommentAuthorization;
    $visible = $builder->batch(OwnerBatch::fromModels([$owner]), new CommentActorData('member', 'viewer'), CommentAudience::Member, $policy)->toBase();
    $latest = $builder->latest($visible, 10);
    $sql = $latest->toSql();
    expect($sql)->toContain('ROW_NUMBER() OVER', 'PARTITION BY commentable_identity_hash', 'created_at DESC, id DESC', 'deleted_at')
        ->and($latest->getBindings())->toContain(10);
    $host = $builder->withVisibleCount(TestCommentTarget::query()->select('id'), CommentActorData::anonymous(), CommentAudience::Public, $policy);
    $sql = $host->toSql();
    expect($sql)->toContain('nvl_visible_comment_count', 'CAST(');
    match ($driver) {
        'pgsql' => expect($sql)->toContain('AS TEXT)', 'COLLATE "C"', 'OCTET_LENGTH'),
        'mysql', 'mariadb' => expect($sql)->toContain('AS CHAR)', 'BINARY', 'OCTET_LENGTH'),
        'sqlite' => expect($sql)->toContain('AS TEXT)', 'COLLATE BINARY', 'AS BLOB'),
    };
})->with(['sqlite', 'pgsql', 'mysql', 'mariadb']);

it('retains an early explicit batch binding when the package provider is registered again', function (): void {
    $policy = new BatchCommentPolicy;
    app()->instance(CommentBatchQueryScope::class, $policy);
    app()->register(CommentsServiceProvider::class, true);
    expect(app(CommentBatchQueryScope::class))->toBe($policy);
});

it('admits exact string keys and prevents Cartesian grants across native morph pairs', function (): void {
    Schema::create('comment_string_targets', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->timestamps();
    });
    $owners = [];
    foreach (['0', '007', '7', 'A'] as $id) {
        $owners[] = TestStringCommentTarget::query()->create(['id' => $id, 'name' => 'String']);
    }
    $other = TestCommentMentionResource::query()->create(['id' => '007', 'tenant_id' => 'host', 'name' => 'Other type', 'registration_number' => 'R']);
    Relation::morphMap(['host:strings' => TestStringCommentTarget::class, 'host:other' => TestCommentMentionResource::class]);
    foreach ([...$owners, $other] as $owner) {
        Comment::query()->create(['commentable_type' => $owner->getMorphClass(), 'commentable_id' => (string) $owner->getKey(), 'body' => 'Exact', 'status' => CommentStatus::Approved]);
    }
    Comment::query()->create(['commentable_type' => 'host:other', 'commentable_id' => '0', 'body' => 'Cartesian foreign pair', 'status' => CommentStatus::Approved]);
    foreach (['a', 'A '] as $id) {
        Comment::query()->create(['commentable_type' => 'host:strings', 'commentable_id' => $id, 'body' => 'Legacy case/space mismatch', 'status' => CommentStatus::Approved]);
    }
    Comment::query()->create(['commentable_type' => 'Host:Strings', 'commentable_id' => 'A', 'body' => 'Legacy morph case mismatch', 'status' => CommentStatus::Approved]);
    $reader = app(ListOwnerCommentSummariesContract::class);
    $partial = $reader->execute([$owners[0], $other], CommentActorData::anonymous());
    expect($partial->owners->{'host:strings'}->{'0'}->visibleCount)->toBe(1)
        ->and($partial->owners->{'host:other'}->{'007'}->visibleCount)->toBe(1);
    $all = $reader->execute($owners, CommentActorData::anonymous());
    foreach ($owners as $owner) {
        expect($all->owners->{'host:strings'}->{(string) $owner->getKey()}->visibleCount)->toBe(1);
    }
    $counts = TestStringCommentTarget::query()->withNvlVisibleCommentCount(CommentActorData::anonymous())->get();
    foreach ($counts as $owner) {
        expect($owner->getAttribute('nvl_visible_comment_count'))->toBe(1);
    }
});
