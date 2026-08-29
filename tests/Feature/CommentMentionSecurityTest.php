<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nvl\Comments\Actions\CreateRichCommentAction;
use Nvl\Comments\Actions\ResolveCommentMentionsAction;
use Nvl\Comments\Actions\SuggestCommentMentionResourcesAction;
use Nvl\Comments\Contracts\CommentMentionResourceResolver;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentMentionResourceData;
use Nvl\Comments\Data\CommentMentionSuggestionData;
use Nvl\Comments\Data\Mutations\CommentDocumentData;
use Nvl\Comments\Data\Mutations\CreateRichCommentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentMentionState;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentMentionResourceRegistry;
use Nvl\Comments\Services\CommentProjectionFactory;
use Nvl\Comments\Tests\Fixtures\TestCommentMentionAuthorization;
use Nvl\Comments\Tests\Fixtures\TestCommentMentionResource;
use Nvl\Comments\Tests\Fixtures\TestCommentMentionResourceResolver;
use Nvl\Comments\Tests\Fixtures\TestCommentMentionUrlResolver;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Comments\Tests\Fixtures\TestViewerIndependentCommentMentionResourceResolver;
use Nvl\Comments\ValueObjects\CommentMentionContext;

/**
 * Register a private custom resource and enable rich mentions.
 */
function commentsMentionSecurityRegisterCustom(): void
{
    config()->set('comments.mentions.enabled', true);
    app(CommentMentionResourceRegistry::class)->register(
        'organization',
        TestCommentMentionResourceResolver::class,
    );
}

/**
 * Register the tenant-scoped declarative resource and enable rich mentions.
 */
function commentsMentionSecurityRegisterEloquent(bool $public = false): void
{
    config()->set('comments.mentions.enabled', true);
    app(CommentMentionResourceRegistry::class)->registerEloquent('organization', [
        'model' => TestCommentMentionResource::class,
        'searchable_fields' => ['name', 'registration_number'],
        'exposed_fields' => ['name', 'registration_number'],
        'label_field' => 'name',
        'authorization' => TestCommentMentionAuthorization::class,
        'url_resolver' => TestCommentMentionUrlResolver::class,
        'public' => $public,
    ]);
}

/**
 * Return one rich document input with a single opaque mention reference.
 *
 * @return array{version: int, blocks: list<array{type: string, children: list<array<string, mixed>>}>}
 */
function commentsMentionSecurityDocument(
    string $resourceId = 'org-1',
    ?string $tokenId = null,
    string $alias = 'organization',
): array {
    return [
        'version' => 1,
        'blocks' => [[
            'type' => 'paragraph',
            'children' => [[
                'type' => 'mention',
                'tokenId' => $tokenId ?? (string) Str::uuid(),
                'resource' => $alias,
                'id' => $resourceId,
            ]],
        ]],
    ];
}

/**
 * Create one rich comment through its reviewed mutation boundary.
 */
function commentsMentionSecurityCreate(
    TestCommentTarget $target,
    CommentActorData $actor,
    string $resourceId = 'org-1',
): Comment {
    return app(CreateRichCommentAction::class)->execute(
        $target,
        new CreateRichCommentData(
            new CommentDocumentData(...commentsMentionSecurityDocument($resourceId)),
        ),
        $actor,
        CommentAudience::Member,
    );
}

/**
 * Build one malformed custom resolver value for a package-boundary regression case.
 */
function commentsMentionSecurityMalformedResource(string $case): CommentMentionResourceData
{
    return match ($case) {
        'field count' => new CommentMentionResourceData(
            id: 'org-1',
            label: 'Organization',
            fields: array_fill_keys(
                array_map(
                    static fn (int $field): string => "field_{$field}",
                    range(1, 26),
                ),
                'value',
            ),
        ),
        'field key length' => new CommentMentionResourceData(
            id: 'org-1',
            label: 'Organization',
            fields: [str_repeat('a', 65) => 'value'],
        ),
        'field value length' => new CommentMentionResourceData(
            id: 'org-1',
            label: 'Organization',
            fields: ['name' => str_repeat('界', 2_049)],
        ),
        'field value encoding' => new CommentMentionResourceData(
            id: 'org-1',
            label: 'Organization',
            fields: ['name' => "\xC3\x28"],
        ),
        'URL length' => new CommentMentionResourceData(
            id: 'org-1',
            label: 'Organization',
            url: '/'.str_repeat('a', 2_048),
        ),
        'URL scheme' => new CommentMentionResourceData(
            id: 'org-1',
            label: 'Organization',
            url: 'javascript:alert(1)',
        ),
        'URL encoding' => new CommentMentionResourceData(
            id: 'org-1',
            label: 'Organization',
            url: "/\xC3\x28",
        ),
    };
}

it('redacts secret-bearing resolver failures from exception and log serialization', function (): void {
    $secret = 'tenant=acme resource=org-private sql=select-secret-label';
    $resolver = new class($secret) implements CommentMentionResourceResolver
    {
        /**
         * Create the secret-bearing failing resolver.
         */
        public function __construct(private readonly string $secret) {}

        /**
         * Fail resource resolution with host-owned sensitive diagnostics.
         *
         * @param  list<string>  $ids
         * @return SupportCollection<int, CommentMentionResourceData>
         */
        public function resolve(
            CommentMentionContext $context,
            array $ids,
        ): SupportCollection {
            throw new RuntimeException($this->secret);
        }

        /**
         * Fail suggestions with host-owned sensitive diagnostics.
         *
         * @return SupportCollection<int, CommentMentionResourceData>
         */
        public function suggest(
            CommentMentionContext $context,
            string $query,
            int $limit,
        ): SupportCollection {
            throw new RuntimeException($this->secret);
        }
    };
    $registry = app(CommentMentionResourceRegistry::class);
    $registry->register('failing', $resolver);
    $context = new CommentMentionContext(
        target: TestCommentTarget::query()->create(['name' => 'Resolver privacy']),
        actor: new CommentActorData('member', 'tenant-a'),
        audience: CommentAudience::Member,
    );

    foreach ([
        fn () => $registry->resolve('failing', $context, ['org-private']),
        fn () => $registry->suggest('failing', $context, 'private', 10),
    ] as $operation) {
        try {
            $operation();
        } catch (InvalidCommentMutationException $exception) {
            $path = storage_path('logs/comment-mention-resolver-privacy.log');
            @unlink($path);
            Log::build(['driver' => 'single', 'path' => $path])
                ->error('Comment mention resolution failed', ['exception' => $exception]);
            $logged = (string) file_get_contents($path);
            @unlink($path);

            expect($exception->getPrevious())->toBeNull()
                ->and((string) $exception)->not->toContain($secret)
                ->and($logged)->not->toContain($secret);

            continue;
        }

        $this->fail('The failing resolver did not raise a package domain exception.');
    }
});

it('projects member rich documents without serializing stored opaque IDs', function (): void {
    commentsMentionSecurityRegisterCustom();
    $target = TestCommentTarget::query()->create(['name' => 'Member projection']);
    $actor = new CommentActorData('member', 'member-1');
    $comment = commentsMentionSecurityCreate($target, $actor);
    $projection = app(CommentProjectionFactory::class)
        ->memberComment($comment, $target, $actor)
        ->toArray();

    expect($projection['document']['blocks'][0]['children'][0])->toBe([
        'type' => 'mention',
        'tokenId' => $comment->mentions()->sole()->token_id,
        'resource' => 'organization',
        'state' => 'resolved',
        'label' => 'Организация',
    ])->not->toHaveKey('id')
        ->and($projection['mentions'][0])->toMatchArray([
            'resourceAlias' => 'organization',
            'state' => 'resolved',
            'labelSnapshot' => 'Организация',
            'resourceId' => 'org-1',
            'currentLabel' => 'Организация',
            'fields' => [],
            'url' => null,
        ]);
});

it('keeps nonpublic mention resources out of viewer-independent public projections', function (): void {
    commentsMentionSecurityRegisterCustom();
    $target = TestCommentTarget::query()->create(['name' => 'Public projection']);
    $actor = new CommentActorData('member', 'member-1');
    $comment = commentsMentionSecurityCreate($target, $actor);
    $projection = app(CommentProjectionFactory::class)
        ->publicComment($comment, $target)
        ->toArray();
    $json = json_encode($projection, JSON_THROW_ON_ERROR);

    expect($projection['mentions'][0])->toMatchArray([
        'state' => 'restricted',
        'labelSnapshot' => 'Организация',
        'resourceId' => null,
        'currentLabel' => null,
        'fields' => [],
        'url' => null,
    ])->and($json)->not->toContain('org-1')
        ->and($json)->not->toContain('member-1');
});

it('allows marked viewer-independent resources in public projections', function (): void {
    config()->set('comments.mentions.enabled', true);
    app(CommentMentionResourceRegistry::class)->register(
        'public-resource',
        TestViewerIndependentCommentMentionResourceResolver::class,
        public: true,
    );
    $target = TestCommentTarget::query()->create(['name' => 'Public live projection']);
    $actor = new CommentActorData('member', 'member-1');
    $comment = app(CreateRichCommentAction::class)->execute(
        $target,
        new CreateRichCommentData(new CommentDocumentData(
            ...commentsMentionSecurityDocument('public-1', alias: 'public-resource'),
        )),
        $actor,
        CommentAudience::Member,
    );
    $projection = app(CommentProjectionFactory::class)
        ->publicComment($comment, $target)
        ->toArray();

    expect($projection['mentions'][0])->toMatchArray([
        'state' => 'resolved',
        'resourceId' => 'public-1',
        'currentLabel' => 'Public public-1',
        'fields' => ['kind' => 'public'],
        'url' => '/public-resources/public-1',
    ]);
});

it('projects deleted resources as snapshot-only missing mentions', function (): void {
    commentsMentionSecurityRegisterEloquent();
    $resource = TestCommentMentionResource::query()->create([
        'id' => 'org-1',
        'tenant_id' => 'tenant-a',
        'name' => 'Original Organization',
        'registration_number' => 'REG-1',
    ]);
    $target = TestCommentTarget::query()->create(['name' => 'Missing projection']);
    $actor = new CommentActorData('member', 'tenant-a');
    $comment = commentsMentionSecurityCreate($target, $actor);
    $resource->delete();
    $mention = app(ResolveCommentMentionsAction::class)
        ->execute($comment, $actor, CommentAudience::Member)
        ->sole();

    expect($mention->state)->toBe(CommentMentionState::Missing)
        ->and($mention->labelSnapshot)->toBe('Original Organization')
        ->and($mention->resourceId)->toBeNull()
        ->and($mention->currentLabel)->toBeNull()
        ->and($mention->fields)->toBe([])
        ->and($mention->url)->toBeNull();
});

it('rejects cross-tenant mention identities during rich mutation', function (): void {
    commentsMentionSecurityRegisterEloquent();
    TestCommentMentionResource::query()->create([
        'id' => 'org-private',
        'tenant_id' => 'tenant-b',
        'name' => 'Private Organization',
        'registration_number' => 'PRIVATE',
    ]);
    $target = TestCommentTarget::query()->create(['name' => 'Cross tenant']);

    expect(fn () => commentsMentionSecurityCreate(
        $target,
        new CommentActorData('member', 'tenant-a'),
        'org-private',
    ))->toThrow(InvalidCommentMutationException::class, 'unavailable or unauthorized');
});

it('conceals nonexistent and cross-tenant resources through the same scoped query path', function (): void {
    commentsMentionSecurityRegisterEloquent();
    TestCommentMentionResource::query()->create([
        'id' => 'org-private',
        'tenant_id' => 'tenant-b',
        'name' => 'Private Organization',
        'registration_number' => 'PRIVATE',
    ]);
    $context = new CommentMentionContext(
        TestCommentTarget::query()->create(['name' => 'Scoped lookup']),
        new CommentActorData('member', 'tenant-a'),
        CommentAudience::Member,
    );
    $registry = app(CommentMentionResourceRegistry::class);

    DB::connection()->enableQueryLog();
    DB::connection()->flushQueryLog();
    $missing = $registry->resolveForProjection('organization', $context, ['org-missing']);
    $missingQueries = DB::connection()->getQueryLog();
    DB::connection()->flushQueryLog();
    $crossTenant = $registry->resolveForProjection('organization', $context, ['org-private']);
    $crossTenantQueries = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();

    expect($missing->sole()->state)->toBe(CommentMentionState::Missing)
        ->and($crossTenant->sole()->state)->toBe(CommentMentionState::Missing)
        ->and($missingQueries)->toHaveCount(1)
        ->and($crossTenantQueries)->toHaveCount(1)
        ->and(array_column($missingQueries, 'query'))
        ->toBe(array_column($crossTenantQueries, 'query'))
        ->and(strtolower($missingQueries[0]['query']))->toContain('tenant_id');
});

it('bounds every custom resolver field and URL before projection', function (string $case): void {
    $resolver = new readonly class($case) implements CommentMentionResourceResolver
    {
        /**
         * Create one deliberately malformed custom resolver.
         */
        public function __construct(private string $case) {}

        /**
         * Return one malformed result to exercise the registry boundary.
         *
         * @param  list<string>  $ids
         * @return SupportCollection<int, CommentMentionResourceData>
         */
        public function resolve(CommentMentionContext $context, array $ids): SupportCollection
        {
            return collect([commentsMentionSecurityMalformedResource($this->case)]);
        }

        /**
         * Return one malformed suggestion to exercise the same DTO boundary.
         *
         * @return SupportCollection<int, CommentMentionResourceData>
         */
        public function suggest(
            CommentMentionContext $context,
            string $query,
            int $limit,
        ): SupportCollection {
            return collect([commentsMentionSecurityMalformedResource($this->case)]);
        }
    };
    $registry = new CommentMentionResourceRegistry(app());
    $registry->register('malformed', $resolver);
    $context = new CommentMentionContext(
        TestCommentTarget::query()->create(['name' => "Malformed {$case}"]),
        new CommentActorData('member', 'tenant-a'),
        CommentAudience::Member,
    );

    expect(fn () => $registry->resolveForProjection('malformed', $context, ['org-1']))
        ->toThrow(InvalidCommentMutationException::class, 'invalid resource batch');
})->with([
    'field count',
    'field key length',
    'field value length',
    'field value encoding',
    'URL length',
    'URL scheme',
    'URL encoding',
]);

it('authorizes targets before validating or querying suggestion resources', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Unauthorized target']);

    expect(fn () => app(SuggestCommentMentionResourcesAction::class)->execute(
        $target,
        CommentActorData::anonymous(),
        CommentAudience::Member,
        'unregistered-secret-alias',
        'anything',
    ))->toThrow(AuthorizationException::class);
});

it('rejects suggestions while rich mentions are disabled', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Disabled mention suggestions']);

    expect(fn () => app(SuggestCommentMentionResourcesAction::class)->execute(
        $target,
        new CommentActorData('member', 'suggestion-author'),
        CommentAudience::Member,
        'unregistered',
        'anything',
    ))->toThrow(InvalidCommentMutationException::class, 'mentions are not enabled');
});

it('rejects malformed resolver-owned mention identity data', function (array $resource): void {
    expect(fn () => new CommentMentionResourceData(...$resource))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'blank identifier' => [[
        'id' => '   ',
        'label' => 'Organization',
    ]],
    'blank resolved label' => [[
        'id' => 'org-1',
        'label' => '   ',
    ]],
    'unavailable resource exposing live data' => [[
        'id' => 'org-1',
        'label' => 'Restricted organization',
        'state' => CommentMentionState::Restricted,
    ]],
]);

it('accepts bounded JSON scalars and safe absolute resource URLs', function (): void {
    $resource = new CommentMentionResourceData(
        id: 'org-1',
        label: 'Organization',
        fields: [
            'score' => 4.5,
            'rank' => 2,
            'verified' => true,
            'parent' => null,
        ],
        url: 'HTTPS://resources.example.test/organizations/org-1',
    );

    expect($resource->fields)->toBe([
        'score' => 4.5,
        'rank' => 2,
        'verified' => true,
        'parent' => null,
    ])->and($resource->url)->toBe('HTTPS://resources.example.test/organizations/org-1');
});

it('rejects unavailable resources as editor suggestions', function (): void {
    $missing = new CommentMentionResourceData(
        id: 'org-missing',
        label: null,
        state: CommentMentionState::Missing,
    );

    expect(fn () => CommentMentionSuggestionData::fromResource($missing))
        ->toThrow(InvalidArgumentException::class, 'require resolved resource data');
});

it('rejects client-owned model column URL label and resolver mention data', function (string $key, mixed $value): void {
    commentsMentionSecurityRegisterCustom();
    $document = commentsMentionSecurityDocument();
    $document['blocks'][0]['children'][0][$key] = $value;
    $target = TestCommentTarget::query()->create(['name' => 'Client injection']);

    expect(fn () => app(CreateRichCommentAction::class)->execute(
        $target,
        new CreateRichCommentData(new CommentDocumentData(...$document)),
        new CommentActorData('member', 'member-1'),
        CommentAudience::Member,
    ))->toThrow(InvalidCommentMutationException::class, 'invalid mention node');
})->with([
    'model' => ['model', TestCommentMentionResource::class],
    'column' => ['column', 'secret'],
    'url' => ['url', 'https://attacker.test'],
    'label' => ['labelSnapshot', 'Attacker label'],
    'resolver' => ['resolver', 'attacker'],
]);

it('keeps rich mention projection query count constant for one versus twenty five comments', function (): void {
    commentsMentionSecurityRegisterEloquent();
    TestCommentMentionResource::query()->create([
        'id' => 'org-1',
        'tenant_id' => 'tenant-a',
        'name' => 'Query Organization',
        'registration_number' => 'REG-1',
    ]);
    $target = TestCommentTarget::query()->create(['name' => 'Query count']);
    $actor = new CommentActorData('member', 'tenant-a');
    $comments = new Collection;

    foreach (range(1, 25) as $position) {
        $comments->push(commentsMentionSecurityCreate($target, $actor));
    }

    $queries = 0;
    DB::listen(static function () use (&$queries): void {
        $queries++;
    });
    app(CommentProjectionFactory::class)->memberCollection(
        new Collection([$comments->first()]),
        $target,
        $actor,
    );
    $oneCommentQueries = $queries;
    $queries = 0;
    app(CommentProjectionFactory::class)->memberCollection($comments, $target, $actor);

    expect($oneCommentQueries)->toBeGreaterThan(0)
        ->and($queries)->toBe($oneCommentQueries);
});
