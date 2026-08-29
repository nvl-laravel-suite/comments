<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Comments\Actions\CreateRichCommentAction;
use Nvl\Comments\Actions\ResolveCommentMentionsAction;
use Nvl\Comments\Actions\SuggestCommentMentionResourcesAction;
use Nvl\Comments\Data\CommentActorData;
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
