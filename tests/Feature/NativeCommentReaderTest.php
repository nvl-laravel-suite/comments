<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentMentionState;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentActor;
use Nvl\Comments\Services\CommentMentionResourceRegistry;
use Nvl\Comments\Services\CommentMetadataRegistry;
use Nvl\Comments\Services\CommentModeration;
use Nvl\Comments\Services\CommentReader;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Support\CommentBatchOwnerAdmission;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\NativeCommentMentionResolver;
use Nvl\Comments\Tests\Fixtures\NativeModerationPolicy;
use Nvl\Comments\Tests\Fixtures\TestCommentMetadataSchema;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Support\Enums\PublicActor;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Owners\OwnerBatch;

it('returns audience scoped public rows without persisted actor identity', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $author = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    $writer->create($target, ['body' => 'Public'], $author, CommentAudience::Public);
    $writer->create($target, ['body' => 'Private', 'visibility' => 'private'], $author, CommentAudience::Member);

    $reader = app(CommentReader::class);
    $public = $reader->paginate($target, PublicActor::Anonymous);
    $member = $reader->paginate($target, $author, CommentAudience::Member);

    expect($public->total())->toBe(1);
    expect($member->total())->toBe(2);
    expect($public->items()[0]['body'])->toBe('Public');
    expect($public->items()[0])->not->toHaveKey('actorId');
    expect($public->items()[0])->not->toHaveKey('actorType');
});

it('exposes pin state and orders thread pages by pins while latest stays chronological', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $author = NativeCommentActor::create(['name' => 'Author']);
    $this->travelTo('2026-11-01 10:00:00');
    $older = app(CommentWriter::class)->create($target, ['body' => 'Pinned'], $author, CommentAudience::Public);
    $this->travel(1)->minute();
    $newer = app(CommentWriter::class)->create($target, ['body' => 'Newest'], $author, CommentAudience::Public);

    $authorization = app(CommentAuthorization::class);
    $scope = app(CommentQueryScope::class);
    $moderation = new NativeModerationPolicy;
    app()->instance(CommentAuthorization::class, $moderation);
    app()->instance(CommentQueryScope::class, $moderation);
    app(CommentModeration::class)->moderate($older, ['status' => CommentStatus::Approved, 'pinned' => true, 'expected_revision' => $older->revision], SystemActor::Background);
    app()->instance(CommentAuthorization::class, $authorization);
    app()->instance(CommentQueryScope::class, $scope);

    $reader = app(CommentReader::class);
    $page = $reader->paginate($target, PublicActor::Anonymous)->items();
    expect(array_column($page, 'id'))->toBe([$older->id, $newer->id])
        ->and(array_column($page, 'pinned'))->toBe([true, false]);
    expect($reader->latest($target, PublicActor::Anonymous))->toMatchArray(['id' => $newer->id, 'pinned' => false]);
    $batch = $reader->forOwners([$target], PublicActor::Anonymous, latestLimit: 2);
    $latest = $batch['values'][$target->getMorphClass()][$target->id]['latest'];
    expect(array_column($latest, 'id'))->toBe([$newer->id, $older->id])
        ->and(array_column($latest, 'pinned'))->toBe([false, true]);
});

it('returns explicit empty owner summaries and bounded latest rows', function (): void {
    $first = TestCommentTarget::create(['name' => 'First']);
    $second = TestCommentTarget::create(['name' => 'Second']);
    $author = NativeCommentActor::create(['name' => 'Author']);
    app(CommentWriter::class)->create($first, ['body' => 'Visible'], $author, CommentAudience::Public);

    $result = app(CommentReader::class)->forOwners([$first, $second], PublicActor::Anonymous, latestLimit: 1);
    $type = $first->getMorphClass();

    expect($result['order'])->toHaveCount(2);
    expect($result['values'][$type][$first->id]['visibleCount'])->toBe(1);
    expect($result['values'][$type][$first->id]['latest'])->toHaveCount(1);
    expect($result['values'][$type][$second->id]['visibleCount'])->toBe(0);
    expect($result['values'][$type][$second->id]['latest'])->toBe([]);
});

it('keeps member visibility tied to the explicit actor on one policy instance', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $firstActor = NativeCommentActor::create(['name' => 'First']);
    $secondActor = NativeCommentActor::create(['name' => 'Second']);
    $writer = app(CommentWriter::class);
    $writer->create($target, ['body' => 'First private', 'visibility' => 'private'], $firstActor, CommentAudience::Member);
    $writer->create($target, ['body' => 'Second private', 'visibility' => 'private'], $secondActor, CommentAudience::Member);
    $reader = app(CommentReader::class);

    $firstRows = $reader->paginate($target, $firstActor, CommentAudience::Member)->items();
    $secondRows = $reader->paginate($target, $secondActor, CommentAudience::Member)->items();

    expect(array_column($firstRows, 'body'))->toBe(['First private']);
    expect(array_column($secondRows, 'body'))->toBe(['Second private']);
});

it('keeps owner batch data queries bounded as owner count grows', function (): void {
    $owners = [];
    for ($index = 0; $index < 8; $index++) {
        $owners[] = TestCommentTarget::create(['name' => "Article {$index}"]);
    }
    $actor = NativeCommentActor::create(['name' => 'Author']);
    app(CommentWriter::class)->create($owners[0], ['body' => 'Visible'], $actor, CommentAudience::Public);
    $reader = app(CommentReader::class);
    $table = (new Comment)->getTable();
    $quoted = DB::connection()->getQueryGrammar()->wrapTable($table);
    $queries = [];
    DB::listen(static function ($event) use (&$queries, $quoted): void {
        if (str_contains($event->sql, $quoted)) {
            $queries[] = $event->sql;
        }
    });

    $reader->forOwners([$owners[0]], PublicActor::Anonymous);
    $single = count($queries);
    $queries = [];
    $reader->forOwners($owners, PublicActor::Anonymous);
    $many = count($queries);

    expect($single)->toBeGreaterThan(0);
    expect($many)->toBe($single);
});

it('keeps revision history with the author and omits editor identity from its rows', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $author = NativeCommentActor::create(['name' => 'Author']);
    $stranger = NativeCommentActor::create(['name' => 'Stranger']);
    $writer = app(CommentWriter::class);
    $comment = $writer->create($target, ['body' => 'Before'], $author);
    $writer->update($comment, ['body' => 'After', 'expected_revision' => $comment->revision], $author);
    $reader = app(CommentReader::class);

    $history = $reader->revisions($comment, $author);
    expect($history->total())->toBe(1);
    expect($history->items()[0]['body'])->toBe('Before');
    expect($history->items()[0])->not->toHaveKey('editedBy');
    expect(fn () => $reader->revisions($comment, $stranger))
        ->toThrow(AuthorizationException::class);
});

it('projects historical metadata by the requested audience', function (): void {
    config(['nvl-comments.metadata.schemas' => [TestCommentMetadataSchema::class]]);
    app()->forgetInstance(CommentMetadataRegistry::class);
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    $comment = $writer->create($target, ['body' => 'Before', 'metadata' => [
        'workflow_event' => 'Member detail', 'workflow_sequence' => 4, 'workflow_approved' => true,
    ]], $actor);
    $writer->update($comment, ['body' => 'After', 'expected_revision' => $comment->revision], $actor);
    $history = app(CommentReader::class)->revisions($comment, $actor, CommentAudience::Public)->items();

    expect($history[0]['metadata'])->toBe([['namespace' => 'workflow', 'values' => ['sequence' => 4]]]);
});

it('counts only replies visible to the current audience and actor', function (string $viewer): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $author = NativeCommentActor::create(['name' => 'Author']);
    $stranger = NativeCommentActor::create(['name' => 'Stranger']);
    $writer = app(CommentWriter::class);
    $parent = $writer->create($target, ['body' => 'Public parent'], $author);
    config(['nvl-comments.moderation.new_status' => 'pending']);
    $writer->create($target, ['body' => 'Pending reply', 'parentId' => $parent->id], $author, CommentAudience::Member);
    $actor = match ($viewer) {
        'author' => $author,
        'stranger' => $stranger,
        'public' => PublicActor::Anonymous,
    };
    $audience = $viewer === 'public' ? CommentAudience::Public : CommentAudience::Member;
    $rows = app(CommentReader::class)->paginate($target, $actor, $audience)->items();

    expect($parent->fresh()->reply_count)->toBe(1)
        ->and($rows[0]['replyCount'])->toBe($viewer === 'author' ? 1 : 0);
})->with(['public', 'stranger', 'author']);

it('returns viewer-safe rich documents directly from native reads', function (string $operation): void {
    config(['nvl-comments.mentions.enabled' => true]);
    app(CommentMentionResourceRegistry::class)->register('member', NativeCommentMentionResolver::class);
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $document = ['version' => 1, 'blocks' => [['type' => 'paragraph', 'children' => [[
        'type' => 'mention', 'tokenId' => (string) Str::uuid(), 'resource' => 'member', 'id' => 'member-1',
    ]]]]];
    $writer = app(CommentWriter::class);
    $comment = $writer->createRich($target, ['document' => $document], $actor);
    if ($operation === 'history') {
        $writer->updateRich($comment, ['document' => ['version' => 1, 'blocks' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'text' => 'Changed']]]]], 'expected_revision' => $comment->revision], $actor);
    }
    $reader = app(CommentReader::class);
    $row = match ($operation) {
        'paginate' => $reader->paginate($target, PublicActor::Anonymous)->items()[0],
        'latest' => $reader->latest($target, PublicActor::Anonymous),
        'history' => $reader->revisions($comment, $actor, CommentAudience::Public)->items()[0],
    };

    expect($row)->toHaveKeys(['document', 'mentions'])
        ->and($row['document']['blocks'][0]['children'][0]['type'])->toBe('mention')
        ->and($row['mentions'][0]['state'])->toBe(CommentMentionState::Restricted)
        ->and($row['mentions'][0]['resourceId'])->toBeNull()
        ->and(json_encode($row, JSON_THROW_ON_ERROR))->not->toContain('member-1');
})->with(['paginate', 'latest', 'history']);

it('reloads authority facts from the writer rather than a stale read replica', function (string $operation): void {
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The isolated read-replica fixture uses in-memory SQLite.');
    }
    $target = TestCommentTarget::create(['name' => 'Canonical target']);
    $actor = NativeCommentActor::create(['name' => 'Canonical actor']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Current'], $actor);
    $attributes = $comment->refresh()->getRawOriginal();
    $table = $connection->getQueryGrammar()->wrapTable($comment->getTable());
    $replica = new PDO('sqlite::memory:');
    $replica->exec('CREATE TABLE native_comment_actors (id INTEGER PRIMARY KEY, name TEXT)');
    $replica->exec('CREATE TABLE comment_test_targets (id TEXT PRIMARY KEY, name TEXT)');
    $replica->prepare('INSERT INTO native_comment_actors (id, name) VALUES (?, ?)')->execute([$actor->id, 'Stale actor']);
    $replica->prepare('INSERT INTO comment_test_targets (id, name) VALUES (?, ?)')->execute([$target->id, 'Stale target']);
    $columns = array_map($connection->getQueryGrammar()->wrap(...), array_keys($attributes));
    $replica->exec('CREATE TABLE '.$table.' ('.implode(', ', array_map(static fn (string $column): string => $column.' TEXT', $columns)).')');
    $replica->prepare('INSERT INTO '.$table.' ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($attributes), '?')).')')->execute(array_values($attributes));
    $comment->forceFill(['status' => 'hidden'])->save();
    $connection->commit();
    $connection->setReadPdo($replica);
    try {
        if (in_array($operation, ['comments', 'latest batch', 'correlated count'], true)) {
            $reader = app(CommentReader::class);
            if ($operation === 'comments') {
                expect($reader->paginate($target, PublicActor::Anonymous)->total())->toBe(0);
            } elseif ($operation === 'latest batch') {
                $value = $reader->forOwners([$target], PublicActor::Anonymous)['values'][$target->getMorphClass()][$target->id];
                expect($value['visibleCount'])->toBe(0)->and($value['latest'])->toBe([]);
            } else {
                $row = $target->newQuery()->withNvlVisibleCommentCount(PublicActor::Anonymous)->findOrFail($target->id);
                expect($row->nvl_visible_comment_count)->toBe(0);
            }

            return;
        }
        $current = match ($operation) {
            'actor' => CommentActor::from($actor)->principal,
            'reload' => app(CommentTargetLocator::class)->reload($target),
            'locate' => app(CommentTargetLocator::class)->locate($comment),
            'batch' => app(CommentBatchOwnerAdmission::class)->admit(OwnerBatch::fromModels([$target]))->owners()[0],
        };

        expect($current->name)->toBe($operation === 'actor' ? 'Canonical actor' : 'Canonical target');
    } finally {
        $connection->setReadPdo(null);
        $connection->beginTransaction();
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
    }
})->with(['actor', 'reload', 'locate', 'batch', 'comments', 'latest batch', 'correlated count']);

it('keeps caller OR visibility scopes inside the mandatory target boundary', function (): void {
    $target = TestCommentTarget::create(['name' => 'Target']);
    $foreign = TestCommentTarget::create(['name' => 'Foreign target']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    $first = $writer->create($target, ['body' => 'First'], $actor);
    $writer->create($foreign, ['body' => 'Foreign'], $actor);
    app()->instance(CommentQueryScope::class, new class implements CommentQueryScope
    {
        public function scopeComments(Builder $query, Authenticatable|SystemActor|PublicActor $actor, Model $target, CommentAudience $audience, CommentAbility $ability): void
        {
            $query->where('body', 'First')->orWhere('body', 'Foreign');
        }
    });

    $rows = app(CommentReader::class)->paginate($target, PublicActor::Anonymous)->items();
    expect(array_column($rows, 'id'))->toBe([$first->id]);
});

it('keeps SQL and mention lookups bounded as rich comment pages grow', function (): void {
    config(['nvl-comments.mentions.enabled' => true]);
    app(CommentMentionResourceRegistry::class)->register('member', NativeCommentMentionResolver::class);
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    foreach (range(1, 20) as $index) {
        $writer->createRich($target, ['document' => ['version' => 1, 'blocks' => [['type' => 'paragraph', 'children' => [[
            'type' => 'mention', 'tokenId' => (string) Str::uuid(), 'resource' => 'member', 'id' => 'member-1',
        ]]]]]], $actor);
    }
    NativeCommentMentionResolver::$resolutions = 0;
    DB::enableQueryLog();
    try {
        $counts = [];
        foreach ([1, 20] as $size) {
            DB::flushQueryLog();
            $rows = app(CommentReader::class)->paginate($target, $actor, CommentAudience::Member, perPage: $size)->items();
            expect($rows)->toHaveCount($size)
                ->and($rows[0]['mentions'][0]['resourceId'])->toBe('member-1');
            $counts[] = count(DB::getQueryLog());
        }
        expect($counts[0])->toBeGreaterThan(0)
            ->and($counts[1])->toBe($counts[0])
            ->and(NativeCommentMentionResolver::$resolutions)->toBe(2);
    } finally {
        DB::disableQueryLog();
        NativeCommentMentionResolver::$resolutions = 0;
    }
});

it('caps reply pages at the configured replies per page', function (): void {
    config(['nvl-comments.threading.maximum_replies_per_page' => 2]);
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    $parent = $writer->create($target, ['body' => 'Parent'], $actor);
    foreach (range(1, 3) as $index) {
        $writer->create($target, ['body' => "Reply {$index}", 'parentId' => $parent->id], $actor);
    }
    $reader = app(CommentReader::class);

    expect($reader->paginate($target, PublicActor::Anonymous, perPage: 50, parentId: $parent->id)->perPage())->toBe(2)
        ->and($reader->paginate($target, PublicActor::Anonymous, perPage: 50)->perPage())->toBe(50);
});
