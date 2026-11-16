<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentMentionState;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Services\CommentMentionResourceRegistry;
use Nvl\Comments\Services\CommentMentions;
use Nvl\Comments\Services\CommentModeration;
use Nvl\Comments\Services\CommentReader;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Tests\Fixtures\CloningTextMatcher;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\NativeCommentMentionResolver;
use Nvl\Comments\Tests\Fixtures\PermissiveCommentMentionAuthorization;
use Nvl\Comments\Tests\Fixtures\TestCommentMentionAuthorization;
use Nvl\Comments\Tests\Fixtures\TestCommentMentionResource;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Comments\Tests\Fixtures\UnmigratedCommentMentionResource;
use Nvl\Support\Contracts\TextMatcher;
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

it('keeps stored rich documents readable after the host lowers the write limits', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    config()->set('nvl-comments.mentions.enabled', true);
    app(CommentMentionResourceRegistry::class)->register('member', NativeCommentMentionResolver::class);
    $document = fn (): array => ['version' => 1, 'blocks' => [
        ['type' => 'paragraph', 'children' => [
            ['type' => 'text', 'text' => 'First paragraph for '],
            ['type' => 'mention', 'tokenId' => (string) Str::uuid(), 'resource' => 'member', 'id' => 'member-1'],
        ]],
        ['type' => 'paragraph', 'children' => [
            ['type' => 'text', 'text' => 'Second paragraph for '],
            ['type' => 'mention', 'tokenId' => (string) Str::uuid(), 'resource' => 'member', 'id' => 'member-1'],
        ]],
    ]];
    $writer = app(CommentWriter::class);
    $comment = $writer->createRich($target, ['document' => $document()], $actor, CommentAudience::Public);
    $comment = $writer->updateRich($comment, ['document' => $document(), 'expected_revision' => $comment->revision], $actor);

    config()->set([
        'nvl-comments.rich_text.maximum_bytes' => 16,
        'nvl-comments.rich_text.maximum_blocks' => 1,
        'nvl-comments.rich_text.maximum_nodes' => 1,
        'nvl-comments.mentions.maximum_per_comment' => 1,
    ]);
    $reader = app(CommentReader::class);

    expect($reader->paginate($target, $actor, CommentAudience::Member)->items()[0]['document']['blocks'])->toHaveCount(2)
        ->and($reader->latest($target, $actor, CommentAudience::Member)['mentions'])->toHaveCount(2)
        ->and($reader->revisions($comment, $actor, CommentAudience::Member)->items()[0]['document']['blocks'])->toHaveCount(2)
        ->and(app(CommentMentions::class)->resolve($comment, $actor, CommentAudience::Member))->toHaveCount(2)
        ->and(fn () => $writer->createRich($target, ['document' => $document()], $actor, CommentAudience::Public))
        ->toThrow(InvalidCommentMutationException::class);
});

it('uses the configured suggestion limit when the caller passes none', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    config()->set(['nvl-comments.mentions.enabled' => true, 'nvl-comments.mentions.suggestion_limit' => 7]);
    app(CommentMentionResourceRegistry::class)->register('member', NativeCommentMentionResolver::class);
    $suggest = fn (?int $limit) => app(CommentMentions::class)->suggest($target, $actor, 'member', 'One', $limit, CommentAudience::Member);

    $suggest(null);
    $configured = NativeCommentMentionResolver::$suggestionLimit;
    $suggest(3);

    expect($configured)->toBe(7)->and(NativeCommentMentionResolver::$suggestionLimit)->toBe(3);
});

it('checks declarative mention resources against the schema only when they are used', function (): void {
    config()->set(['nvl-comments.mentions.enabled' => true, 'nvl-comments.mentions.resources' => ['organization' => [
        'model' => UnmigratedCommentMentionResource::class,
        'searchable_fields' => ['name'], 'exposed_fields' => ['name'], 'label_field' => 'name',
        'authorization' => TestCommentMentionAuthorization::class, 'public' => false,
    ]]]);
    app()->forgetInstance(CommentMentionResourceRegistry::class);
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $comment = app(CommentWriter::class)->create($target, ['body' => 'Plain'], $actor);

    expect($comment->exists)->toBeTrue()
        ->and(app(CommentMentionResourceRegistry::class)->has('organization'))->toBeTrue()
        ->and(implode(' ', $statements))->not->toContain('comment_mention_unmigrated_resources')
        ->and(fn () => app(CommentMentions::class)->suggest($target, $actor, 'organization', 'Acme', audience: CommentAudience::Member))
        ->toThrow(InvalidArgumentException::class, 'Declarative comment mention resources reference nonexistent columns.')
        ->and(app(CommentMentionResourceRegistry::class)->diagnostics()['ready'])->toBeFalse();
});

it('treats a mention identifier that cannot be a key of its resource as missing without querying the key column', function (): void {
    config()->set(['nvl-comments.mentions.enabled' => true, 'nvl-comments.mentions.resources' => ['author' => [
        'model' => TestCommentTarget::class,
        'searchable_fields' => ['name'], 'exposed_fields' => ['name'], 'label_field' => 'name',
        'authorization' => PermissiveCommentMentionAuthorization::class, 'public' => false,
    ]]]);
    app()->forgetInstance(CommentMentionResourceRegistry::class);
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $document = fn (string $id): array => ['version' => 1, 'blocks' => [['type' => 'paragraph', 'children' => [
        ['type' => 'mention', 'tokenId' => (string) Str::uuid(), 'resource' => 'author', 'id' => $id],
    ]]]];
    $bindings = [];
    DB::listen(function (QueryExecuted $query) use (&$bindings): void {
        $bindings = [...$bindings, ...$query->bindings];
    });

    expect(app(CommentWriter::class)->createRich($target, ['document' => $document($target->id)], $actor)->body)->toBe('@Article')
        ->and(fn () => app(CommentWriter::class)->createRich($target, ['document' => $document('not-a-uuid')], $actor))
        ->toThrow(InvalidCommentMutationException::class)
        ->and($bindings)->not->toContain('not-a-uuid');
});

it('rejects NUL bytes in stored text instead of failing in the database', function (Closure $operation): void {
    config()->set('nvl-comments.mentions.enabled', true);
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Clean'], $actor);

    expect(fn () => $operation($target, $actor, $comment))->toThrow(InvalidArgumentException::class)
        ->and(Comment::query()->count())->toBe(1)
        ->and($comment->fresh()->body)->toBe('Clean');
})->with([
    'plain body' => [fn ($target, $actor) => app(CommentWriter::class)->create($target, ['body' => "a\0b"], $actor)],
    'updated body' => [fn ($target, $actor, $comment) => app(CommentWriter::class)->update($comment, ['body' => "a\0b", 'expected_revision' => 1], $actor)],
    'locale' => [fn ($target, $actor) => app(CommentWriter::class)->create($target, ['body' => 'Body', 'locale' => "e\0n"], $actor)],
    'rich text node' => [fn ($target, $actor) => app(CommentWriter::class)->createRich($target, ['document' => ['version' => 1, 'blocks' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'text' => "a\0b"]]]]]], $actor)],
    'report reason' => [fn ($target, $actor, $comment) => app(CommentModeration::class)->report($comment, ['reason' => "sp\0am"], $actor)],
]);

it('suggests Unicode mention resources case-insensitively', function (): void {
    config()->set(['nvl-comments.mentions.enabled' => true, 'nvl-comments.mentions.resources' => ['author' => [
        'model' => TestCommentTarget::class,
        'searchable_fields' => ['name'], 'exposed_fields' => ['name'], 'label_field' => 'name',
        'authorization' => PermissiveCommentMentionAuthorization::class, 'public' => false,
    ]]]);
    app()->forgetInstance(CommentMentionResourceRegistry::class);

    $target = TestCommentTarget::create(['name' => 'Article']);
    $unicodeResource = TestCommentTarget::create(['name' => 'Георги Иванов !%_']);
    TestCommentTarget::create(['name' => 'Георги Иванов !xy']);
    $actor = NativeCommentActor::create(['name' => 'Author']);

    $suggestions = app(CommentMentions::class)->suggest($target, $actor, 'author', 'георги иванов !%_', audience: CommentAudience::Member);

    expect(array_column($suggestions, 'id'))->toBe([(string) $unicodeResource->id]);
});

it('keeps authorization when a host matcher returns a cloned builder', function (): void {
    app()->bind(TextMatcher::class, CloningTextMatcher::class);
    config()->set(['nvl-comments.mentions.enabled' => true, 'nvl-comments.mentions.resources' => ['company' => [
        'model' => TestCommentMentionResource::class,
        'searchable_fields' => ['name', 'registration_number'], 'exposed_fields' => ['name'], 'label_field' => 'name',
        'authorization' => TestCommentMentionAuthorization::class, 'public' => false,
    ]]]);
    app()->forgetInstance(CommentMentionResourceRegistry::class);

    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $tenant = (string) $actor->id;
    TestCommentMentionResource::create(['id' => 'r1', 'tenant_id' => $tenant, 'name' => 'Acme Holdings', 'registration_number' => 'X-1']);
    TestCommentMentionResource::create(['id' => 'r2', 'tenant_id' => $tenant, 'name' => 'Beta Trading', 'registration_number' => 'ACME-2']);
    TestCommentMentionResource::create(['id' => 'r3', 'tenant_id' => $tenant, 'name' => 'Gamma', 'registration_number' => 'G-3']);
    TestCommentMentionResource::create(['id' => 'r4', 'tenant_id' => 'other-tenant', 'name' => 'Acme Foreign', 'registration_number' => 'F-4']);

    $suggestions = app(CommentMentions::class)->suggest($target, $actor, 'company', 'acme', audience: CommentAudience::Member);

    expect(array_column($suggestions, 'id'))->toBe(['r1', 'r2']);
});
