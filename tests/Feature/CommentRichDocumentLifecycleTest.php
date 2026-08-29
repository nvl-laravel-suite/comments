<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Nvl\Comments\Actions\AnonymizeCommentAction;
use Nvl\Comments\Actions\CreateCommentAction;
use Nvl\Comments\Actions\CreateRichCommentAction;
use Nvl\Comments\Actions\DeleteCommentAction;
use Nvl\Comments\Actions\RestoreCommentRevisionAction;
use Nvl\Comments\Actions\UpdateCommentAction;
use Nvl\Comments\Actions\UpdateRichCommentAction;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\AnonymizeCommentData;
use Nvl\Comments\Data\Mutations\CommentDocumentData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Data\Mutations\CreateRichCommentData;
use Nvl\Comments\Data\Mutations\DeleteCommentData;
use Nvl\Comments\Data\Mutations\RestoreCommentRevisionData;
use Nvl\Comments\Data\Mutations\UpdateCommentData;
use Nvl\Comments\Data\Mutations\UpdateRichCommentData;
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Exceptions\CommentIdempotencyConflictException;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Exceptions\StaleCommentException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentMention;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Services\CommentCreationWriter;
use Nvl\Comments\Services\CommentDocumentNormalizer;
use Nvl\Comments\Services\CommentMentionResourceRegistry;
use Nvl\Comments\Tests\Fixtures\TestCommentMentionResourceResolver;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Comments\ValueObjects\CommentMentionContext;

/**
 * Register the deterministic mention resolver used by rich lifecycle tests.
 */
function commentsRichRegisterResolver(): void
{
    config()->set('comments.mentions.enabled', true);
    app(CommentMentionResourceRegistry::class)->register(
        'organization',
        TestCommentMentionResourceResolver::class,
    );
}

/**
 * Return one valid rich document input.
 *
 * @return array{version: int, blocks: list<array{type: string, children: list<array<string, mixed>>}>}
 */
function commentsRichDocument(?string $tokenId = null): array
{
    return [
        'version' => 1,
        'blocks' => [[
            'type' => 'paragraph',
            'children' => [
                ['type' => 'text', 'text' => "Contact\r\n"],
                [
                    'type' => 'mention',
                    'tokenId' => $tokenId ?? (string) Str::uuid(),
                    'resource' => 'organization',
                    'id' => 'org-1',
                ],
                ['type' => 'hard_break'],
                ['type' => 'text', 'text' => "Cafe\u{0301}"],
            ],
        ]],
    ];
}

/**
 * Create the minimal application-owned rich comment storage contract.
 */
function commentsRichCreateApplicationOwnedTables(): void
{
    Schema::create('tenant_comments', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('commentable_type', 100);
        $table->string('commentable_id', 255);
        $table->char('commentable_identity_hash', 64);
        $table->uuid('root_id')->nullable();
        $table->uuid('parent_id')->nullable();
        $table->unsignedSmallInteger('depth')->default(0);
        $table->string('actor_type', 100)->nullable();
        $table->string('actor_id', 255)->nullable();
        $table->char('actor_identity_hash', 64)->nullable();
        $table->uuid('idempotency_key')->nullable()->unique();
        $table->char('idempotency_hash', 64)->nullable();
        $table->text('body');
        $table->string('format', 32)->default('plain');
        $table->string('locale', 35)->nullable();
        $table->string('status', 32)->default('pending');
        $table->char('status_hash', 64);
        $table->string('visibility', 32)->default('public');
        $table->char('visibility_hash', 64);
        $table->json('tags')->nullable();
        $table->json('metadata')->nullable();
        $table->json('document')->nullable();
        $table->unsignedBigInteger('revision')->default(1);
        $table->unsignedInteger('reply_count')->default(0);
        $table->unsignedInteger('reaction_count')->default(0);
        $table->unsignedInteger('report_count')->default(0);
        $table->unsignedInteger('open_report_count')->default(0);
        $table->boolean('is_pinned')->default(false);
        $table->timestamp('edited_at')->nullable();
        $table->timestamp('anonymized_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('tenant_comment_revisions', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('comment_id');
        $table->unsignedBigInteger('revision');
        $table->text('body');
        $table->string('format', 32);
        $table->string('locale', 35)->nullable();
        $table->json('tags')->nullable();
        $table->json('metadata')->nullable();
        $table->json('document')->nullable();
        $table->string('edited_by_type', 100)->nullable();
        $table->string('edited_by', 255)->nullable();
        $table->timestamp('created_at')->useCurrent();
        $table->foreign('comment_id')->references('id')->on('tenant_comments')->cascadeOnDelete();
        $table->unique(['comment_id', 'revision']);
    });
    Schema::create('tenant_comment_mentions', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('comment_id');
        $table->uuid('token_id');
        $table->string('resource_alias', 100);
        $table->string('resource_id', 255);
        $table->char('resource_identity_hash', 64);
        $table->string('label_snapshot', 255);
        $table->unsignedSmallInteger('position');
        $table->timestamps();
        $table->foreign('comment_id')->references('id')->on('tenant_comments')->cascadeOnDelete();
        $table->unique(['comment_id', 'token_id']);
    });
}

it('keeps both public creation actions as thin dedicated entrypoints', function (): void {
    foreach ([CreateCommentAction::class, CreateRichCommentAction::class] as $actionClass) {
        $parameters = (new ReflectionClass($actionClass))->getConstructor()?->getParameters();

        expect($parameters)->toHaveCount(1)
            ->and($parameters[0]->getType()?->getName())->toBe(CommentCreationWriter::class);
    }
});

it('creates forward-only rich document and normalized mention storage', function (): void {
    expect(Schema::hasColumns(CommentsTables::Comments, ['document']))->toBeTrue()
        ->and(Schema::hasColumns(CommentsTables::Revisions, ['document']))->toBeTrue()
        ->and(Schema::hasColumns(CommentsTables::Mentions, [
            'id',
            'comment_id',
            'token_id',
            'resource_alias',
            'resource_id',
            'resource_identity_hash',
            'label_snapshot',
            'position',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

it('upgrades released schemas and rolls rich storage back safely', function (): void {
    config()->set('database.connections.comments_rich_upgrade', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    $defaultConnection = config('database.default');
    config()->set('database.default', 'comments_rich_upgrade');
    config()->set('comments.connection', null);

    foreach ([
        '2026_07_27_110001_create_comments_table.php',
        '2026_07_27_110002_create_comment_reactions_table.php',
        '2026_07_27_110003_create_comment_revisions_table.php',
        '2026_07_27_110004_create_comment_reports_table.php',
    ] as $migration) {
        (require __DIR__."/../../database/migrations/{$migration}")->up();
    }

    $documents = require __DIR__
        .'/../../database/migrations/2026_08_28_000001_add_comment_documents.php';
    $mentions = require __DIR__
        .'/../../database/migrations/2026_08_28_000002_create_comment_mentions_table.php';
    $documents->up();
    $mentions->up();

    expect(Schema::connection('comments_rich_upgrade')->hasColumn('comments', 'document'))
        ->toBeTrue()
        ->and(Schema::connection('comments_rich_upgrade')->hasColumn('comment_revisions', 'document'))
        ->toBeTrue()
        ->and(Schema::connection('comments_rich_upgrade')->hasTable('comment_mentions'))
        ->toBeTrue();

    $mentions->down();
    $documents->down();

    expect(Schema::connection('comments_rich_upgrade')->hasTable('comment_mentions'))
        ->toBeFalse()
        ->and(Schema::connection('comments_rich_upgrade')->hasColumn('comments', 'document'))
        ->toBeFalse()
        ->and(Schema::connection('comments_rich_upgrade')->hasColumn('comment_revisions', 'document'))
        ->toBeFalse();

    config()->set('database.default', $defaultConnection);
});

it('refuses vendor rich migrations for application-owned table names', function (): void {
    config()->set('comments.tables.comment_mentions', 'tenant_comment_mentions');
    $migration = require __DIR__
        .'/../../database/migrations/2026_08_28_000002_create_comment_mentions_table.php';

    expect(fn () => $migration->up())->toThrow(LogicException::class, 'canonical tables')
        ->and(Schema::hasTable('tenant_comment_mentions'))->toBeFalse();
});

it('writes every mention field to application-owned compatible storage', function (): void {
    $table = 'tenant_comment_mentions';
    Schema::create($table, function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('comment_id');
        $table->uuid('token_id');
        $table->string('resource_alias', 100);
        $table->string('resource_id', 255);
        $table->char('resource_identity_hash', 64);
        $table->string('label_snapshot', 255);
        $table->unsignedSmallInteger('position');
        $table->timestamps();
        $table->foreign('comment_id')->references('id')->on('comments')->cascadeOnDelete();
        $table->unique(['comment_id', 'token_id']);
        $table->index(['resource_alias', 'resource_identity_hash']);
        $table->index(['comment_id', 'position']);
    });
    config()->set('comments.tables.comment_mentions', $table);
    commentsRichRegisterResolver();
    $target = TestCommentTarget::query()->create(['name' => 'Application rich storage']);
    $comment = app(CreateRichCommentAction::class)->execute(
        $target,
        new CreateRichCommentData(new CommentDocumentData(...commentsRichDocument())),
        new CommentActorData('member', 'application-rich-author'),
        CommentAudience::Member,
    );

    $row = DB::table($table)->where('comment_id', $comment->id)->sole();

    expect((array) $row)->toHaveKeys([
        'id',
        'comment_id',
        'token_id',
        'resource_alias',
        'resource_id',
        'resource_identity_hash',
        'label_snapshot',
        'position',
        'created_at',
        'updated_at',
    ]);
});

it('writes rich comments revision documents and mentions to application-owned storage', function (): void {
    commentsRichCreateApplicationOwnedTables();
    config()->set('comments.tables.comments', 'tenant_comments');
    config()->set('comments.tables.comment_revisions', 'tenant_comment_revisions');
    config()->set('comments.tables.comment_mentions', 'tenant_comment_mentions');
    commentsRichRegisterResolver();
    $target = TestCommentTarget::query()->create(['name' => 'Application owned rich lifecycle']);
    $actor = new CommentActorData('member', 'application-owned-rich-author');
    $comment = app(CreateRichCommentAction::class)->execute(
        $target,
        new CreateRichCommentData(new CommentDocumentData(...commentsRichDocument())),
        $actor,
        CommentAudience::Member,
    );
    $updated = app(UpdateRichCommentAction::class)->execute(
        $comment,
        new UpdateRichCommentData(
            new CommentDocumentData(...commentsRichDocument()),
            expectedRevision: 1,
        ),
        $actor,
        CommentAudience::Member,
    );

    expect(DB::table('tenant_comments')->where('id', $comment->id)->value('document'))
        ->not->toBeNull()
        ->and(DB::table('tenant_comment_revisions')->where('comment_id', $comment->id)->value('document'))
        ->not->toBeNull()
        ->and(DB::table('tenant_comment_mentions')->where('comment_id', $comment->id)->count())
        ->toBe(1)
        ->and(DB::table(CommentsTables::Comments)->where('id', $comment->id)->exists())
        ->toBeFalse()
        ->and($updated->getTable())->toBe('tenant_comments')
        ->and($updated->revisions()->sole()->getTable())->toBe('tenant_comment_revisions')
        ->and($updated->mentions()->sole()->getTable())->toBe('tenant_comment_mentions');
});

it('normalizes strict version one documents and derives unicode plain text', function (): void {
    commentsRichRegisterResolver();
    $target = TestCommentTarget::query()->create(['name' => 'Rich target']);
    $actor = new CommentActorData('member', 'rich-author');
    $comment = app(CreateRichCommentAction::class)->execute(
        $target,
        new CreateRichCommentData(
            document: new CommentDocumentData(...commentsRichDocument()),
        ),
        $actor,
        CommentAudience::Member,
    );

    expect($comment->format)->toBe(CommentFormat::RichText)
        ->and($comment->body)->toBe("Contact\n@Организация\nCafé")
        ->and($comment->document['blocks'][0]['children'][1]['labelSnapshot'])
        ->toBe('Организация')
        ->and($comment->mentions)->toHaveCount(1)
        ->and($comment->mentions->first()?->label_snapshot)->toBe('Организация')
        ->and($comment->toArray())->not->toHaveKey('document')
        ->and($comment->mentions->first()?->toArray())
        ->not->toHaveKey('resource_identity_hash');
});

it('rejects unknown rich document root keys', function (): void {
    expect(fn () => CreateRichCommentData::validateAndCreate([
        'document' => [
            'version' => 1,
            'blocks' => [],
            'html' => '<b>unsafe</b>',
        ],
    ]))->toThrow(ValidationException::class);
});

it('rejects malformed and unbounded rich documents', function (array $document): void {
    commentsRichRegisterResolver();
    $normalizer = app(CommentDocumentNormalizer::class);
    $target = TestCommentTarget::query()->create(['name' => 'Invalid rich target']);

    expect(fn () => $normalizer->normalizeInput(
        new CommentDocumentData(...$document),
        new CommentMentionContext(
            target: $target,
            actor: new CommentActorData('member', 'rich-author'),
            audience: CommentAudience::Member,
        ),
    ))->toThrow(InvalidCommentMutationException::class);
})->with([
    'unknown node type' => [[
        'version' => 1,
        'blocks' => [['type' => 'paragraph', 'children' => [['type' => 'link', 'url' => 'https://invalid.test']]]],
    ]],
    'nested children' => [[
        'version' => 1,
        'blocks' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'text' => 'x', 'children' => []]]]],
    ]],
    'invalid token uuid' => [[
        'version' => 1,
        'blocks' => [['type' => 'paragraph', 'children' => [[
            'type' => 'mention', 'tokenId' => 'not-a-uuid', 'resource' => 'organization', 'id' => 'org-1',
        ]]]],
    ]],
    'client label' => [[
        'version' => 1,
        'blocks' => [['type' => 'paragraph', 'children' => [[
            'type' => 'mention', 'tokenId' => (string) Str::uuid(), 'resource' => 'organization', 'id' => 'org-1', 'labelSnapshot' => 'Client label',
        ]]]],
    ]],
    'non scalar id' => [[
        'version' => 1,
        'blocks' => [['type' => 'paragraph', 'children' => [[
            'type' => 'mention', 'tokenId' => (string) Str::uuid(), 'resource' => 'organization', 'id' => ['org-1'],
        ]]]],
    ]],
    'empty blocks' => [['version' => 1, 'blocks' => []]],
    'non-map block' => [['version' => 1, 'blocks' => ['paragraph']]],
]);

it('accepts input object keys in any order and stores canonical key order', function (): void {
    commentsRichRegisterResolver();
    $target = TestCommentTarget::query()->create(['name' => 'Canonical key target']);
    $document = commentsRichDocument();
    $mention = $document['blocks'][0]['children'][1];
    $document['blocks'][0]['children'][1] = [
        'id' => $mention['id'],
        'resource' => $mention['resource'],
        'tokenId' => $mention['tokenId'],
        'type' => $mention['type'],
    ];
    $normalized = app(CommentDocumentNormalizer::class)->normalizeInput(
        new CommentDocumentData(...$document),
        new CommentMentionContext(
            $target,
            new CommentActorData('member', 'canonical-key-author'),
            CommentAudience::Member,
        ),
    );

    expect(array_keys($normalized->blocks[0]['children'][1]))->toBe([
        'type',
        'tokenId',
        'resource',
        'id',
        'labelSnapshot',
    ]);
});

it('rejects duplicate tokens and unknown resource aliases', function (): void {
    commentsRichRegisterResolver();
    $target = TestCommentTarget::query()->create(['name' => 'Duplicate token target']);
    $context = new CommentMentionContext(
        target: $target,
        actor: new CommentActorData('member', 'rich-author'),
        audience: CommentAudience::Member,
    );
    $token = (string) Str::uuid();
    $duplicate = commentsRichDocument($token);
    $duplicate['blocks'][0]['children'][] = [
        'type' => 'mention',
        'tokenId' => $token,
        'resource' => 'organization',
        'id' => 'org-2',
    ];
    $unknown = commentsRichDocument();
    $unknown['blocks'][0]['children'][1]['resource'] = 'unknown';

    expect(fn () => app(CommentDocumentNormalizer::class)->normalizeInput(
        new CommentDocumentData(...$duplicate),
        $context,
    ))->toThrow(InvalidCommentMutationException::class)
        ->and(fn () => app(CommentDocumentNormalizer::class)->normalizeInput(
            new CommentDocumentData(...$unknown),
            $context,
        ))->toThrow(InvalidCommentMutationException::class);
});

it('rejects case-variant duplicate mention tokens before persistence', function (): void {
    commentsRichRegisterResolver();
    $target = TestCommentTarget::query()->create(['name' => 'Case duplicate token target']);
    $token = (string) Str::uuid();
    $document = commentsRichDocument(strtoupper($token));
    $document['blocks'][0]['children'][] = [
        'type' => 'mention',
        'tokenId' => strtolower($token),
        'resource' => 'organization',
        'id' => 'org-2',
    ];

    expect(fn () => app(CommentDocumentNormalizer::class)->normalizeUnresolved(
        new CommentDocumentData(...$document),
    ))->toThrow(
        InvalidCommentMutationException::class,
        'invalid or duplicate mention token',
    );
});

it('rejects rich format mutations through both legacy actions', function (): void {
    config()->set('comments.content.allowed_formats', ['plain', 'markdown', 'rich_text']);
    $target = TestCommentTarget::query()->create(['name' => 'Legacy rich action target']);
    $actor = new CommentActorData('member', 'legacy-rich-author');

    expect(fn () => app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData(body: 'Bypass', format: CommentFormat::RichText),
        $actor,
        CommentAudience::Member,
    ))->toThrow(InvalidCommentMutationException::class)
        ->and(Comment::query()->where('commentable_id', $target->getKey())->count())->toBe(0);

    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData(body: 'Plain'),
        $actor,
        CommentAudience::Member,
    );

    expect(fn () => app(UpdateCommentAction::class)->execute(
        $comment,
        new UpdateCommentData(
            body: 'Bypass update',
            expectedRevision: 1,
            format: CommentFormat::RichText,
        ),
        $actor,
        CommentAudience::Member,
    ))->toThrow(InvalidCommentMutationException::class)
        ->and($comment->refresh()->format)->toBe(CommentFormat::Plain)
        ->and($comment->document)->toBeNull()
        ->and($comment->mentions()->count())->toBe(0);
});

it('updates and restores rich revisions with exact current mention rows', function (): void {
    commentsRichRegisterResolver();
    $target = TestCommentTarget::query()->create(['name' => 'Rich revision target']);
    $actor = new CommentActorData('member', 'rich-revision-author');
    $firstToken = (string) Str::uuid();
    $comment = app(CreateRichCommentAction::class)->execute(
        $target,
        new CreateRichCommentData(
            document: new CommentDocumentData(...commentsRichDocument($firstToken)),
            metadata: ['legacy_private' => 'preserved'],
        ),
        $actor,
        CommentAudience::Member,
    );
    $secondDocument = commentsRichDocument((string) Str::uuid());
    $secondDocument['blocks'][0]['children'][1]['id'] = 'org-2';
    $updated = app(UpdateRichCommentAction::class)->execute(
        $comment,
        new UpdateRichCommentData(
            document: new CommentDocumentData(...$secondDocument),
            expectedRevision: 1,
        ),
        $actor,
        CommentAudience::Member,
    );
    $historical = CommentRevision::query()
        ->where('comment_id', $comment->id)
        ->where('revision', 1)
        ->sole();

    expect($updated->revision)->toBe(2)
        ->and($updated->body)->toContain('@Second Organization')
        ->and($updated->metadata)->toBe(['legacy_private' => 'preserved'])
        ->and($updated->mentions()->sole()->resource_id)->toBe('org-2')
        ->and($historical->document['blocks'][0]['children'][1]['labelSnapshot'])
        ->toBe('Организация')
        ->and($historical->toArray())->not->toHaveKey('document');

    $restored = app(RestoreCommentRevisionAction::class)->execute(
        $updated,
        $historical,
        new RestoreCommentRevisionData(expectedRevision: 2),
        $actor,
        CommentAudience::Member,
    );

    expect($restored->revision)->toBe(3)
        ->and($restored->body)->toBe("Contact\n@Организация\nCafé")
        ->and($restored->mentions()->sole()->token_id)->toBe($firstToken)
        ->and(CommentRevision::query()->where('comment_id', $comment->id)->count())
        ->toBe(2);
});

it('keeps mention rows on soft delete and cascades them on hard delete', function (): void {
    commentsRichRegisterResolver();
    $target = TestCommentTarget::query()->create(['name' => 'Rich deletion target']);
    $actor = new CommentActorData('member', 'rich-delete-author');
    $comment = app(CreateRichCommentAction::class)->execute(
        $target,
        new CreateRichCommentData(new CommentDocumentData(...commentsRichDocument())),
        $actor,
        CommentAudience::Member,
    );

    app(DeleteCommentAction::class)->execute(
        $comment,
        new DeleteCommentData(expectedRevision: 1),
        $actor,
        CommentAudience::Member,
    );

    expect(CommentMention::query()->where('comment_id', $comment->id)->count())->toBe(1);

    if (DB::connection()->getDriverName() !== 'sqlite') {
        Comment::query()->withTrashed()->findOrFail($comment->id)->forceDelete();

        expect(CommentMention::query()->where('comment_id', $comment->id)->count())->toBe(0);
    }
});

it('scrubs rich documents mention rows and historical snapshots on anonymization', function (): void {
    commentsRichRegisterResolver();
    $target = TestCommentTarget::query()->create(['name' => 'Rich anonymization target']);
    $actor = new CommentActorData('member', 'rich-anonymize-author');
    $comment = app(CreateRichCommentAction::class)->execute(
        $target,
        new CreateRichCommentData(new CommentDocumentData(...commentsRichDocument())),
        $actor,
        CommentAudience::Member,
    );
    $updated = app(UpdateRichCommentAction::class)->execute(
        $comment,
        new UpdateRichCommentData(
            document: new CommentDocumentData(...commentsRichDocument()),
            expectedRevision: 1,
        ),
        $actor,
        CommentAudience::Member,
    );
    $anonymized = app(AnonymizeCommentAction::class)->execute(
        $updated,
        new AnonymizeCommentData(expectedRevision: 2, reason: 'Privacy request'),
        CommentActorData::system(),
        CommentAudience::Management,
    );

    expect($anonymized->document)->toBeNull()
        ->and($anonymized->body)->toBe('')
        ->and(CommentMention::query()->where('comment_id', $comment->id)->count())->toBe(0)
        ->and(CommentRevision::query()->where('comment_id', $comment->id)->count())->toBe(0);
});

it('replays identical rich idempotency requests and rejects changed documents', function (): void {
    commentsRichRegisterResolver();
    $target = TestCommentTarget::query()->create(['name' => 'Rich idempotency target']);
    $actor = new CommentActorData('member', 'rich-idempotency-author');
    $key = (string) Str::uuid();
    $document = commentsRichDocument();
    $data = new CreateRichCommentData(
        new CommentDocumentData(...$document),
        idempotencyKey: $key,
    );
    $created = app(CreateRichCommentAction::class)->execute(
        $target,
        $data,
        $actor,
        CommentAudience::Member,
    );
    $replayed = app(CreateRichCommentAction::class)->execute(
        $target,
        $data,
        $actor,
        CommentAudience::Member,
    );
    $changed = $document;
    $changed['blocks'][0]['children'][0]['text'] = 'Changed ';

    expect($replayed->id)->toBe($created->id)
        ->and($replayed->wasRecentlyCreated)->toBeFalse()
        ->and(Comment::query()->where('idempotency_key', $key)->count())->toBe(1)
        ->and(fn () => app(CreateRichCommentAction::class)->execute(
            $target,
            new CreateRichCommentData(
                new CommentDocumentData(...$changed),
                idempotencyKey: $key,
            ),
            $actor,
            CommentAudience::Member,
        ))->toThrow(CommentIdempotencyConflictException::class);
});

it('rolls back rich revision and mention changes when the comment save is vetoed', function (): void {
    commentsRichRegisterResolver();
    $target = TestCommentTarget::query()->create(['name' => 'Rich rollback target']);
    $actor = new CommentActorData('member', 'rich-rollback-author');
    $comment = app(CreateRichCommentAction::class)->execute(
        $target,
        new CreateRichCommentData(new CommentDocumentData(...commentsRichDocument())),
        $actor,
        CommentAudience::Member,
    );
    $originalToken = $comment->mentions()->sole()->token_id;
    Event::listen(
        'eloquent.updating: '.Comment::class,
        static fn (Comment $candidate): bool => $candidate->id !== $comment->id,
    );

    expect(fn () => app(UpdateRichCommentAction::class)->execute(
        $comment,
        new UpdateRichCommentData(
            new CommentDocumentData(...commentsRichDocument()),
            expectedRevision: 1,
        ),
        $actor,
        CommentAudience::Member,
    ))->toThrow(InvalidCommentMutationException::class)
        ->and($comment->refresh()->revision)->toBe(1)
        ->and($comment->mentions()->sole()->token_id)->toBe($originalToken)
        ->and(CommentRevision::query()->where('comment_id', $comment->id)->count())->toBe(0);
});

it('allows one rich update for an expected revision and rejects the stale retry', function (): void {
    commentsRichRegisterResolver();
    $target = TestCommentTarget::query()->create(['name' => 'Rich concurrency target']);
    $actor = new CommentActorData('member', 'rich-concurrency-author');
    $comment = app(CreateRichCommentAction::class)->execute(
        $target,
        new CreateRichCommentData(new CommentDocumentData(...commentsRichDocument())),
        $actor,
        CommentAudience::Member,
    );
    $action = app(UpdateRichCommentAction::class);
    $action->execute(
        $comment,
        new UpdateRichCommentData(
            new CommentDocumentData(...commentsRichDocument()),
            expectedRevision: 1,
        ),
        $actor,
        CommentAudience::Member,
    );

    expect(fn () => $action->execute(
        $comment,
        new UpdateRichCommentData(
            new CommentDocumentData(...commentsRichDocument()),
            expectedRevision: 1,
        ),
        $actor,
        CommentAudience::Member,
    ))->toThrow(StaleCommentException::class)
        ->and(CommentRevision::query()->where('comment_id', $comment->id)->count())->toBe(1);
});

it('enforces configured block node byte mention and resource bounds', function (): void {
    commentsRichRegisterResolver();
    $target = TestCommentTarget::query()->create(['name' => 'Rich bounds target']);
    $context = new CommentMentionContext(
        $target,
        new CommentActorData('member', 'rich-bounds-author'),
        CommentAudience::Member,
    );
    $normalizer = app(CommentDocumentNormalizer::class);
    $valid = commentsRichDocument();

    config()->set('comments.rich_text.maximum_blocks', 1);
    $tooManyBlocks = $valid;
    $tooManyBlocks['blocks'][] = $valid['blocks'][0];
    expect(fn () => $normalizer->normalizeInput(
        new CommentDocumentData(...$tooManyBlocks),
        $context,
    ))->toThrow(InvalidCommentMutationException::class);

    config()->set('comments.rich_text.maximum_blocks', 100);
    config()->set('comments.rich_text.maximum_nodes', 1);
    expect(fn () => $normalizer->normalizeInput(
        new CommentDocumentData(...$valid),
        $context,
    ))->toThrow(InvalidCommentMutationException::class);

    config()->set('comments.rich_text.maximum_nodes', 500);
    config()->set('comments.rich_text.maximum_bytes', 20);
    expect(fn () => $normalizer->normalizeInput(
        new CommentDocumentData(...$valid),
        $context,
    ))->toThrow(InvalidCommentMutationException::class);

    config()->set('comments.rich_text.maximum_bytes', 32_768);
    config()->set('comments.mentions.maximum_per_comment', 1);
    $tooManyMentions = $valid;
    $tooManyMentions['blocks'][0]['children'][] = [
        'type' => 'mention',
        'tokenId' => (string) Str::uuid(),
        'resource' => 'organization',
        'id' => 'org-2',
    ];
    expect(fn () => $normalizer->normalizeInput(
        new CommentDocumentData(...$tooManyMentions),
        $context,
    ))->toThrow(InvalidCommentMutationException::class);

    config()->set('comments.mentions.maximum_per_comment', 25);
    config()->set('comments.mentions.maximum_resource_types_per_comment', 1);
    app(CommentMentionResourceRegistry::class)->register(
        'team',
        TestCommentMentionResourceResolver::class,
    );
    $tooManyResources = $valid;
    $tooManyResources['blocks'][0]['children'][] = [
        'type' => 'mention',
        'tokenId' => (string) Str::uuid(),
        'resource' => 'team',
        'id' => 'org-2',
    ];
    expect(fn () => $normalizer->normalizeInput(
        new CommentDocumentData(...$tooManyResources),
        $context,
    ))->toThrow(InvalidCommentMutationException::class);
});

it('rejects documents that exceed the byte ceiling after server labels are added', function (): void {
    commentsRichRegisterResolver();
    config()->set('comments.rich_text.maximum_bytes', 400);
    $target = TestCommentTarget::query()->create(['name' => 'Resolved byte bound target']);
    $document = commentsRichDocument();
    $document['blocks'][0]['children'][1]['id'] = 'org-long';
    $context = new CommentMentionContext(
        $target,
        new CommentActorData('member', 'resolved-byte-author'),
        CommentAudience::Member,
    );

    expect(strlen(json_encode($document, JSON_THROW_ON_ERROR)))->toBeLessThan(400)
        ->and(fn () => app(CommentDocumentNormalizer::class)->normalizeInput(
            new CommentDocumentData(...$document),
            $context,
        ))->toThrow(InvalidCommentMutationException::class);
});

it('enforces every hard rich document and mention ceiling above host configuration', function (): void {
    commentsRichRegisterResolver();
    $target = TestCommentTarget::query()->create(['name' => 'Hard rich bounds target']);
    $context = new CommentMentionContext(
        $target,
        new CommentActorData('member', 'hard-bounds-author'),
        CommentAudience::Member,
    );
    $normalizer = app(CommentDocumentNormalizer::class);

    config()->set('comments.rich_text.maximum_blocks', 10_000);
    config()->set('comments.rich_text.maximum_nodes', 10_000);
    config()->set('comments.rich_text.maximum_bytes', 1_000_000);
    config()->set('comments.mentions.maximum_per_comment', 10_000);
    config()->set('comments.mentions.maximum_resource_types_per_comment', 10_000);
    config()->set('comments.mentions.maximum_batch_size', 10_000);

    $blockOverflow = [
        'version' => 1,
        'blocks' => array_fill(0, 251, [
            'type' => 'paragraph',
            'children' => [['type' => 'text', 'text' => 'x']],
        ]),
    ];
    $nodeOverflow = [
        'version' => 1,
        'blocks' => [[
            'type' => 'paragraph',
            'children' => array_fill(0, 1_001, ['type' => 'text', 'text' => 'x']),
        ]],
    ];
    $byteOverflow = [
        'version' => 1,
        'blocks' => [[
            'type' => 'paragraph',
            'children' => [['type' => 'text', 'text' => str_repeat('x', 131_073)]],
        ]],
    ];
    $mentionOverflow = [
        'version' => 1,
        'blocks' => [[
            'type' => 'paragraph',
            'children' => array_map(
                static fn (int $position): array => [
                    'type' => 'mention',
                    'tokenId' => (string) Str::uuid(),
                    'resource' => 'organization',
                    'id' => "org-{$position}",
                ],
                range(1, 101),
            ),
        ]],
    ];
    $resourceOverflow = [
        'version' => 1,
        'blocks' => [[
            'type' => 'paragraph',
            'children' => array_map(
                static fn (int $position): array => [
                    'type' => 'mention',
                    'tokenId' => (string) Str::uuid(),
                    'resource' => "resource{$position}",
                    'id' => 'one',
                ],
                range(1, 21),
            ),
        ]],
    ];

    foreach ([$blockOverflow, $nodeOverflow, $byteOverflow, $mentionOverflow, $resourceOverflow] as $document) {
        expect(fn () => $normalizer->normalizeInput(
            new CommentDocumentData(...$document),
            $context,
        ))->toThrow(InvalidCommentMutationException::class);
    }

    expect(fn () => app(CommentMentionResourceRegistry::class)->resolve(
        'organization',
        $context,
        array_map(static fn (int $position): string => "org-{$position}", range(1, 101)),
    ))->toThrow(InvalidCommentMutationException::class);
});

it('rejects malformed stored rich snapshots without live resolution', function (array $document): void {
    expect(fn () => app(CommentDocumentNormalizer::class)->normalizeStored($document))
        ->toThrow(InvalidCommentMutationException::class);
})->with([
    'wrong version' => [[
        'version' => 2,
        'blocks' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'text' => 'x']]]],
    ]],
    'missing label snapshot' => [[
        'version' => 1,
        'blocks' => [['type' => 'paragraph', 'children' => [[
            'type' => 'mention',
            'tokenId' => '0198ef65-9f91-72a5-a1f0-1d8aa20a8631',
            'resource' => 'organization',
            'id' => 'org-1',
        ]]]],
    ]],
    'blank label snapshot' => [[
        'version' => 1,
        'blocks' => [['type' => 'paragraph', 'children' => [[
            'type' => 'mention',
            'tokenId' => '0198ef65-9f91-72a5-a1f0-1d8aa20a8631',
            'resource' => 'organization',
            'id' => 'org-1',
            'labelSnapshot' => '   ',
        ]]]],
    ]],
    'oversized label snapshot' => [[
        'version' => 1,
        'blocks' => [['type' => 'paragraph', 'children' => [[
            'type' => 'mention',
            'tokenId' => '0198ef65-9f91-72a5-a1f0-1d8aa20a8631',
            'resource' => 'organization',
            'id' => 'org-1',
            'labelSnapshot' => str_repeat('x', 256),
        ]]]],
    ]],
    'unknown stored node key' => [[
        'version' => 1,
        'blocks' => [['type' => 'paragraph', 'children' => [[
            'type' => 'text',
            'text' => 'x',
            'html' => '<b>x</b>',
        ]]]],
    ]],
]);

it('rolls back revision restore when its stored rich snapshot is malformed', function (): void {
    commentsRichRegisterResolver();
    $target = TestCommentTarget::query()->create(['name' => 'Malformed restore target']);
    $actor = new CommentActorData('member', 'malformed-restore-author');
    $comment = app(CreateRichCommentAction::class)->execute(
        $target,
        new CreateRichCommentData(new CommentDocumentData(...commentsRichDocument())),
        $actor,
        CommentAudience::Member,
    );
    $updated = app(UpdateRichCommentAction::class)->execute(
        $comment,
        new UpdateRichCommentData(
            new CommentDocumentData(...commentsRichDocument()),
            expectedRevision: 1,
        ),
        $actor,
        CommentAudience::Member,
    );
    $revision = $updated->revisions()->where('revision', 1)->sole();
    $currentToken = $updated->mentions()->sole()->token_id;
    DB::table(CommentsTables::Revisions)->where('id', $revision->id)->update([
        'document' => json_encode([
            'version' => 1,
            'blocks' => [['type' => 'paragraph', 'children' => [[
                'type' => 'mention',
                'tokenId' => (string) Str::uuid(),
                'resource' => 'organization',
                'id' => 'org-1',
            ]]]],
        ], JSON_THROW_ON_ERROR),
    ]);

    expect(fn () => app(RestoreCommentRevisionAction::class)->execute(
        $updated,
        CommentRevision::query()->findOrFail($revision->id),
        new RestoreCommentRevisionData(expectedRevision: 2),
        $actor,
        CommentAudience::Member,
    ))->toThrow(InvalidCommentMutationException::class)
        ->and($updated->refresh()->revision)->toBe(2)
        ->and($updated->mentions()->sole()->token_id)->toBe($currentToken)
        ->and($updated->revisions()->count())->toBe(1);
});
