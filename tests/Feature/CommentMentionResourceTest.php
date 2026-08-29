<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Nvl\Comments\Actions\SuggestCommentMentionResourcesAction;
use Nvl\Comments\Contracts\CommentMentionResourceResolver;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentMentionResourceData;
use Nvl\Comments\Data\CommentMentionSuggestionData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentMentionState;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Services\CommentMentionResourceRegistry;
use Nvl\Comments\Tests\Fixtures\TestCommentMentionAuthorization;
use Nvl\Comments\Tests\Fixtures\TestCommentMentionResource;
use Nvl\Comments\Tests\Fixtures\TestCommentMentionResourceResolver;
use Nvl\Comments\Tests\Fixtures\TestCommentMentionUrlResolver;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Comments\Tests\Fixtures\UnresolvableCommentMentionResourceResolver;
use Nvl\Comments\ValueObjects\CommentMentionContext;

/**
 * Register the declarative package-test mention resource.
 */
function commentsMentionRegisterEloquent(
    string $alias = 'organization',
    bool $public = false,
): CommentMentionResourceRegistry {
    $registry = app(CommentMentionResourceRegistry::class);
    $registry->registerEloquent($alias, [
        'model' => TestCommentMentionResource::class,
        'searchable_fields' => ['name', 'registration_number'],
        'exposed_fields' => ['name', 'registration_number'],
        'label_field' => 'name',
        'authorization' => TestCommentMentionAuthorization::class,
        'url_resolver' => TestCommentMentionUrlResolver::class,
        'public' => $public,
    ]);

    return $registry;
}

/**
 * Create one package-test mention context.
 */
function commentsMentionContext(string $tenant = 'tenant-a'): CommentMentionContext
{
    return new CommentMentionContext(
        target: TestCommentTarget::query()->create(['name' => "Target {$tenant}"]),
        actor: new CommentActorData('member', $tenant),
        audience: CommentAudience::Member,
    );
}

/**
 * Seed deterministic authorized, restricted, and deleted mention resources.
 *
 * @return array{authorized: TestCommentMentionResource, restricted: TestCommentMentionResource, deleted: TestCommentMentionResource}
 */
function commentsMentionSeedResources(): array
{
    $authorized = TestCommentMentionResource::query()->create([
        'id' => 'org-a',
        'tenant_id' => 'tenant-a',
        'name' => 'Alpha 100%_ Foundation',
        'registration_number' => 'REG-002',
    ]);
    TestCommentMentionResource::query()->create([
        'id' => 'org-b',
        'tenant_id' => 'tenant-a',
        'name' => 'Alpha Foundation',
        'registration_number' => 'REG-001',
    ]);
    $restricted = TestCommentMentionResource::query()->create([
        'id' => 'org-private',
        'tenant_id' => 'tenant-b',
        'name' => 'Private Tenant',
        'registration_number' => 'SECRET-001',
    ]);
    $deleted = TestCommentMentionResource::query()->create([
        'id' => 'org-deleted',
        'tenant_id' => 'tenant-a',
        'name' => 'Deleted Tenant',
        'registration_number' => 'DELETED-001',
    ]);
    $deleted->delete();

    return compact('authorized', 'restricted', 'deleted');
}

it('ships rich mention registration disabled with every bounded default', function (): void {
    expect(config('comments.mentions'))->toMatchArray([
        'enabled' => false,
        'maximum_per_comment' => 25,
        'maximum_resource_types_per_comment' => 10,
        'suggestion_limit' => 10,
        'maximum_suggestion_limit' => 20,
        'maximum_query_length' => 160,
        'maximum_batch_size' => 100,
        'resources' => [],
    ]);
});

it('resolves declarative Eloquent resources through authorization and allowlisted fields', function (): void {
    commentsMentionSeedResources();
    $registry = commentsMentionRegisterEloquent();
    $resolved = $registry->resolveForProjection(
        'organization',
        commentsMentionContext(),
        ['org-a', 'org-private', 'org-deleted', 'org-missing'],
    );

    expect($resolved)->toHaveCount(4)
        ->and($resolved[0])->toBeInstanceOf(CommentMentionResourceData::class)
        ->and($resolved[0]->state)->toBe(CommentMentionState::Resolved)
        ->and($resolved[0]->id)->toBe('org-a')
        ->and($resolved[0]->label)->toBe('Alpha 100%_ Foundation')
        ->and($resolved[0]->fields)->toBe([
            'name' => 'Alpha 100%_ Foundation',
            'registration_number' => 'REG-002',
        ])
        ->and($resolved[0]->fields)->not->toHaveKeys(['tenant_id', 'secret'])
        ->and($resolved[0]->url)->toBe('/organizations/org-a')
        ->and($resolved[1]->state)->toBe(CommentMentionState::Missing)
        ->and($resolved[1]->label)->toBeNull()
        ->and($resolved[2]->state)->toBe(CommentMentionState::Missing)
        ->and($resolved[3]->state)->toBe(CommentMentionState::Missing);
});

it('returns deterministic bounded suggestions and escapes SQL wildcard input', function (): void {
    commentsMentionSeedResources();
    commentsMentionRegisterEloquent();
    config()->set('comments.mentions.enabled', true);
    $context = commentsMentionContext();
    $action = app(SuggestCommentMentionResourcesAction::class);
    $suggestions = $action->execute(
        $context->target,
        $context->actor,
        $context->audience,
        'organization',
        'Alpha',
        20,
    );
    $literalWildcard = $action->execute(
        $context->target,
        $context->actor,
        $context->audience,
        'organization',
        '100%_',
        20,
    );
    $metacharacters = $action->execute(
        $context->target,
        $context->actor,
        $context->audience,
        'organization',
        "x%' OR 1=1 --",
        20,
    );

    expect($suggestions)->toBeInstanceOf(Collection::class)
        ->and($suggestions)->each->toBeInstanceOf(CommentMentionSuggestionData::class)
        ->and($suggestions->pluck('id')->all())->toBe(['org-a', 'org-b'])
        ->and($literalWildcard->pluck('id')->all())->toBe(['org-a'])
        ->and($metacharacters)->toBeEmpty();
});

it('supports custom resource resolvers through the same registry', function (): void {
    $registry = app(CommentMentionResourceRegistry::class);
    $registry->register('custom', TestCommentMentionResourceResolver::class);
    $context = commentsMentionContext();

    expect($registry->resolve('custom', $context, ['org-2', 'org-1', 'org-2'])
        ->pluck('id')->all())->toBe(['org-2', 'org-1'])
        ->and($registry->suggest('custom', $context, 'organization', 10)
            ->pluck('id')->all())->toBe(['org-2']);
});

it('registers configured custom and declarative definitions through one registry', function (): void {
    config()->set('comments.mentions.resources', [
        'organization' => [
            'model' => TestCommentMentionResource::class,
            'searchable_fields' => ['name'],
            'exposed_fields' => ['name'],
            'label_field' => 'name',
            'authorization' => TestCommentMentionAuthorization::class,
            'public' => false,
        ],
        'custom' => [
            'resolver' => TestCommentMentionResourceResolver::class,
        ],
    ]);
    $registry = new CommentMentionResourceRegistry(app());
    $registry->registerConfigured();

    expect($registry->aliases())->toBe(['custom', 'organization']);
});

it('converts malformed custom resolver output to the package domain exception', function (): void {
    $registry = app(CommentMentionResourceRegistry::class);
    $resolver = Mockery::mock(CommentMentionResourceResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn(collect(['not-a-resource-dto']));
    $resolver->shouldReceive('suggest')->once()->andReturn(collect([new stdClass]));
    $registry->register('invalid', $resolver);
    $builderResolver = Mockery::mock(CommentMentionResourceResolver::class);
    $builderResolver->shouldReceive('resolve')->once()->andReturn(
        TestCommentMentionResource::query(),
    );
    $registry->register('invalid-builder', $builderResolver);
    $context = commentsMentionContext();

    expect(fn () => $registry->resolve('invalid', $context, ['one']))
        ->toThrow(InvalidCommentMutationException::class, 'invalid resource batch')
        ->and(fn () => $registry->suggest('invalid', $context, 'one', 10))
        ->toThrow(InvalidCommentMutationException::class, 'invalid suggestion batch')
        ->and(fn () => $registry->resolve('invalid-builder', $context, ['one']))
        ->toThrow(InvalidCommentMutationException::class, 'invalid resource batch');
});

it('rejects alias collisions invalid classes and unsafe declarative fields', function (): void {
    $registry = commentsMentionRegisterEloquent();

    expect(fn () => $registry->register('organization', TestCommentMentionResourceResolver::class))
        ->toThrow(InvalidArgumentException::class, 'unique')
        ->and(fn () => $registry->register('missing', 'Missing\\Resolver'))
        ->toThrow(InvalidArgumentException::class, 'implement')
        ->and(fn () => $registry->register(
            'unresolvable',
            UnresolvableCommentMentionResourceResolver::class,
        ))->toThrow(InvalidArgumentException::class, 'container-resolvable')
        ->and(fn () => $registry->register(
            'unsafe-public',
            TestCommentMentionResourceResolver::class,
            public: true,
        ))->toThrow(InvalidArgumentException::class, 'ViewerIndependent')
        ->and(fn () => $registry->registerEloquent('missing-field', [
            'model' => TestCommentMentionResource::class,
            'searchable_fields' => ['does_not_exist'],
            'exposed_fields' => ['name'],
            'label_field' => 'name',
            'authorization' => TestCommentMentionAuthorization::class,
            'public' => false,
        ]))->toThrow(InvalidArgumentException::class, 'columns')
        ->and(fn () => $registry->registerEloquent('hidden-field', [
            'model' => TestCommentMentionResource::class,
            'searchable_fields' => ['name'],
            'exposed_fields' => ['name', 'secret'],
            'label_field' => 'name',
            'authorization' => TestCommentMentionAuthorization::class,
            'public' => false,
        ]))->toThrow(InvalidArgumentException::class, 'hidden or guarded')
        ->and(fn () => $registry->registerEloquent('unsafe-field', [
            'model' => TestCommentMentionResource::class,
            'searchable_fields' => ['name) OR 1=1 --'],
            'exposed_fields' => ['name'],
            'label_field' => 'name',
            'authorization' => TestCommentMentionAuthorization::class,
            'public' => false,
        ]))->toThrow(InvalidArgumentException::class, 'fields')
        ->and(fn () => $registry->registerEloquent('label-not-exposed', [
            'model' => TestCommentMentionResource::class,
            'searchable_fields' => ['name'],
            'exposed_fields' => ['registration_number'],
            'label_field' => 'name',
            'authorization' => TestCommentMentionAuthorization::class,
            'public' => false,
        ]))->toThrow(InvalidArgumentException::class, 'label membership')
        ->and(fn () => $registry->registerEloquent('invalid-authorization', [
            'model' => TestCommentMentionResource::class,
            'searchable_fields' => ['name'],
            'exposed_fields' => ['name'],
            'label_field' => 'name',
            'authorization' => stdClass::class,
            'public' => false,
        ]))->toThrow(InvalidArgumentException::class, 'configuration')
        ->and(fn () => $registry->registerEloquent('invalid-url', [
            'model' => TestCommentMentionResource::class,
            'searchable_fields' => ['name'],
            'exposed_fields' => ['name'],
            'label_field' => 'name',
            'authorization' => TestCommentMentionAuthorization::class,
            'url_resolver' => stdClass::class,
            'public' => false,
        ]))->toThrow(InvalidArgumentException::class, 'URL resolver');
});

it('enforces hard query suggestion and batch bounds', function (): void {
    $registry = app(CommentMentionResourceRegistry::class);
    $registry->register('custom', TestCommentMentionResourceResolver::class);
    $context = commentsMentionContext();
    config()->set('comments.mentions.maximum_query_length', 10_000);
    config()->set('comments.mentions.maximum_suggestion_limit', 10_000);
    config()->set('comments.mentions.maximum_batch_size', 10_000);

    expect(fn () => $registry->suggest('custom', $context, str_repeat('x', 161), 10))
        ->toThrow(InvalidCommentMutationException::class)
        ->and(fn () => $registry->suggest('custom', $context, 'x', 21))
        ->toThrow(InvalidCommentMutationException::class)
        ->and(fn () => $registry->resolve(
            'custom',
            $context,
            array_map(static fn (int $id): string => (string) $id, range(1, 101)),
        ))->toThrow(InvalidCommentMutationException::class);
});
