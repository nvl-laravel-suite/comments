<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Nvl\Comments\Contracts\CommentMentionResourceAuthorization;
use Nvl\Comments\Contracts\CommentMentionResourceResolver;
use Nvl\Comments\Contracts\CommentMentionUrlResolver;
use Nvl\Comments\Contracts\ViewerIndependentCommentMentionResource;
use Nvl\Comments\Data\CommentMentionResourceData;
use Nvl\Comments\Enums\CommentMentionState;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Comments\ValueObjects\CommentMentionContext;
use Throwable;

/**
 * Routes bounded mention work to explicitly registered custom or Eloquent resources.
 */
final class CommentMentionResourceRegistry
{
    private const int MAXIMUM_DIAGNOSTIC_ALIASES = 100;

    private const int MAXIMUM_DECLARATIVE_FIELDS = 25;

    private const int MAXIMUM_DECLARATIVE_FIELD_NAME_BYTES = 64;

    /**
     * @var array<string, array{resolver: class-string<CommentMentionResourceResolver>|CommentMentionResourceResolver, public: bool}>
     */
    private array $resources = [];

    /**
     * Create the mention resource alias registry.
     */
    public function __construct(private readonly Container $container) {}

    /**
     * Register every server-owned configured mention resource definition.
     */
    public function registerConfigured(): void
    {
        $configured = config('comments.mentions.resources', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException(
                'comments.mentions.resources must be an alias-keyed array.',
            );
        }

        foreach ($configured as $alias => $definition) {
            if (! is_string($alias) || ! is_array($definition)) {
                throw new InvalidArgumentException(
                    'Every configured comment mention resource is invalid.',
                );
            }

            $resolver = $definition['resolver'] ?? null;
            $model = $definition['model'] ?? null;

            if (($resolver === null) === ($model === null)) {
                throw new InvalidArgumentException(
                    'Comment mention resources require exactly one resolver or model.',
                );
            }

            if ($resolver !== null) {
                if (! is_string($resolver)
                    || array_diff(array_keys($definition), ['resolver', 'public']) !== []) {
                    throw new InvalidArgumentException(
                        'Configured custom comment mention resources are invalid.',
                    );
                }

                $public = $definition['public'] ?? false;

                if (! is_bool($public)) {
                    throw new InvalidArgumentException(
                        'Configured comment mention public markers must be boolean.',
                    );
                }

                $this->register($alias, $resolver, $public);

                continue;
            }

            if (array_diff(array_keys($definition), [
                'model',
                'searchable_fields',
                'exposed_fields',
                'label_field',
                'authorization',
                'url_resolver',
                'public',
            ]) !== []) {
                throw new InvalidArgumentException(
                    'Configured declarative comment mention resources are invalid.',
                );
            }

            $this->registerEloquent($alias, $definition);
        }
    }

    /**
     * Register one stable alias and custom container-resolvable resolver.
     */
    public function register(
        string $alias,
        string|CommentMentionResourceResolver $resolver,
        bool $public = false,
    ): void {
        $this->assertNewAlias($alias);

        if (is_string($resolver)
            && ! is_a($resolver, CommentMentionResourceResolver::class, true)) {
            throw new InvalidArgumentException(
                'Comment mention resource resolvers must implement CommentMentionResourceResolver.',
            );
        }

        if (is_string($resolver)) {
            try {
                $resolved = $this->container->make($resolver);
            } catch (Throwable) {
                throw new InvalidArgumentException(
                    'Comment mention resource resolvers must be container-resolvable.',
                );
            }

            if (! $resolved instanceof CommentMentionResourceResolver) {
                throw new InvalidArgumentException(
                    'Comment mention resource resolvers must be container-resolvable.',
                );
            }
        }

        $viewerIndependent = is_string($resolver)
            ? is_a($resolver, ViewerIndependentCommentMentionResource::class, true)
            : $resolver instanceof ViewerIndependentCommentMentionResource;

        if ($public && ! $viewerIndependent) {
            throw new InvalidArgumentException(
                'Public custom mention resources must implement ViewerIndependentCommentMentionResource.',
            );
        }

        $this->resources[$alias] = ['resolver' => $resolver, 'public' => $public];
        ksort($this->resources);
    }

    /**
     * Register one declarative Eloquent resource definition.
     *
     * @param  array<array-key, mixed>  $definition
     */
    public function registerEloquent(string $alias, array $definition): void
    {
        $this->assertNewAlias($alias);
        $modelClass = $definition['model'] ?? null;
        $searchableFields = $definition['searchable_fields'] ?? null;
        $exposedFields = $definition['exposed_fields'] ?? null;
        $labelField = $definition['label_field'] ?? null;
        $authorizationClass = $definition['authorization'] ?? null;
        $urlResolverClass = $definition['url_resolver'] ?? null;
        $public = $definition['public'] ?? null;

        if (! is_string($modelClass) || ! is_a($modelClass, Model::class, true)) {
            throw new InvalidArgumentException(
                'Declarative comment mention resources require an Eloquent model.',
            );
        }

        if (! is_array($searchableFields)
            || ! is_array($exposedFields)
            || ! is_string($labelField)
            || ! is_string($authorizationClass)
            || ! is_a($authorizationClass, CommentMentionResourceAuthorization::class, true)
            || ! is_bool($public)) {
            throw new InvalidArgumentException(
                'Declarative comment mention resource configuration is invalid.',
            );
        }

        $searchable = $this->fields($searchableFields, 'searchable');
        $exposed = $this->fields($exposedFields, 'exposed');

        if ($searchable === []
            || $exposed === []
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $labelField) !== 1
            || ! in_array($labelField, $exposed, true)) {
            throw new InvalidArgumentException(
                'Declarative comment mention fields and label membership are invalid.',
            );
        }

        $model = new $modelClass;
        $table = $model->getTable();
        $columns = $model->getConnection()->getSchemaBuilder()->getColumnListing($table);
        $requiredColumns = array_unique([
            $model->getKeyName(),
            $labelField,
            ...$searchable,
            ...$exposed,
        ]);

        if ($columns === [] || array_diff($requiredColumns, $columns) !== []) {
            throw new InvalidArgumentException(
                'Declarative comment mention resources reference nonexistent columns.',
            );
        }

        $explicitlyGuarded = array_values(array_filter(
            $model->getGuarded(),
            static fn (string $field): bool => $field !== '*',
        ));
        $sensitive = array_unique([...$model->getHidden(), ...$explicitlyGuarded]);

        if (array_intersect($requiredColumns, $sensitive) !== []) {
            throw new InvalidArgumentException(
                'Declarative comment mention fields must not expose hidden or guarded attributes.',
            );
        }

        $authorization = $this->container->make($authorizationClass);

        if (! $authorization instanceof CommentMentionResourceAuthorization) {
            throw new InvalidArgumentException(
                'The configured comment mention resource authorization is invalid.',
            );
        }

        $urlResolver = null;

        if ($urlResolverClass !== null) {
            if (! is_string($urlResolverClass)
                || ! is_a($urlResolverClass, CommentMentionUrlResolver::class, true)) {
                throw new InvalidArgumentException(
                    'The configured comment mention URL resolver is invalid.',
                );
            }

            $urlResolver = $this->container->make($urlResolverClass);

            if (! $urlResolver instanceof CommentMentionUrlResolver) {
                throw new InvalidArgumentException(
                    'The configured comment mention URL resolver is invalid.',
                );
            }
        }

        $this->resources[$alias] = [
            'resolver' => new EloquentCommentMentionResourceResolver(
                modelClass: $modelClass,
                searchableFields: $searchable,
                exposedFields: $exposed,
                labelField: $labelField,
                authorization: $authorization,
                urlResolver: $urlResolver,
            ),
            'public' => $public,
        ];
        ksort($this->resources);
    }

    /**
     * Resolve one strict bounded batch for mutation-time mention compilation.
     *
     * @param  list<string>  $ids
     * @return Collection<int, CommentMentionResourceData>
     */
    public function resolve(
        string $alias,
        CommentMentionContext $context,
        array $ids,
    ): Collection {
        $ids = $this->boundedIds($ids);
        $resolved = $this->resolvedBatch($alias, $context, $ids);
        $byId = $this->validateResolved($resolved, $ids, 'resource');

        foreach ($ids as $id) {
            if (! isset($byId[$id])
                || $byId[$id]->state !== CommentMentionState::Resolved) {
                throw new InvalidCommentMutationException(
                    'Comment document contains an unavailable or unauthorized mention resource.',
                );
            }
        }

        return collect($ids)->map(
            static fn (string $id): CommentMentionResourceData => $byId[$id],
        );
    }

    /**
     * Resolve one projection batch while retaining safe unavailable states.
     *
     * @param  list<string>  $ids
     * @return Collection<int, CommentMentionResourceData>
     */
    public function resolveForProjection(
        string $alias,
        CommentMentionContext $context,
        array $ids,
    ): Collection {
        $ids = $this->boundedIds($ids);
        $resolved = $this->resolvedBatch($alias, $context, $ids);
        $byId = $this->validateResolved($resolved, $ids, 'resource');

        return collect($ids)->map(
            static fn (string $id): CommentMentionResourceData => $byId[$id]
                ?? new CommentMentionResourceData(
                    id: $id,
                    label: null,
                    state: CommentMentionState::Missing,
                ),
        );
    }

    /**
     * Suggest one bounded deterministic batch from a registered resource.
     *
     * @return Collection<int, CommentMentionResourceData>
     */
    public function suggest(
        string $alias,
        CommentMentionContext $context,
        string $query,
        int $limit,
    ): Collection {
        $maximumQuery = min(160, CommentsConfiguration::positiveInteger(
            'comments.mentions.maximum_query_length',
            160,
        ));
        $maximumLimit = min(20, CommentsConfiguration::positiveInteger(
            'comments.mentions.maximum_suggestion_limit',
            20,
        ));

        if (! mb_check_encoding($query, 'UTF-8')
            || preg_match('/\S/u', $query) !== 1
            || mb_strlen($query) > $maximumQuery
            || $limit < 1
            || $limit > $maximumLimit) {
            throw new InvalidCommentMutationException(
                'Comment mention suggestion input is outside the configured bounds.',
            );
        }

        $resolver = $this->resolver($alias);

        try {
            $resolved = $resolver->suggest($context, $query, $limit);
        } catch (Throwable) {
            throw new InvalidCommentMutationException(
                'Comment mention resolution returned an invalid suggestion batch.',
            );
        }

        $byId = $this->validateResolved($resolved, null, 'suggestion');

        if (count($byId) > $limit) {
            throw new InvalidCommentMutationException(
                'Comment mention resolution returned an invalid suggestion batch.',
            );
        }

        foreach ($byId as $resource) {
            if ($resource->state !== CommentMentionState::Resolved) {
                throw new InvalidCommentMutationException(
                    'Comment mention resolution returned an invalid suggestion batch.',
                );
            }
        }

        return collect(array_values($byId));
    }

    /**
     * Determine whether one alias may resolve live data for shared public projections.
     */
    public function isViewerIndependent(string $alias): bool
    {
        return ($this->resources[$alias]['public'] ?? false) === true;
    }

    /**
     * Determine whether one resource alias is registered.
     */
    public function has(string $alias): bool
    {
        return isset($this->resources[$alias]);
    }

    /**
     * Return registered aliases in deterministic order.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        return array_keys($this->resources);
    }

    /**
     * Return a bounded snapshot of the registered definitions used by consumers.
     *
     * @return array{aliases: list<string>, ready: bool, registered: int, truncated: bool}
     */
    public function diagnostics(): array
    {
        $aliases = $this->aliases();
        $diagnosticAliases = array_slice($aliases, 0, self::MAXIMUM_DIAGNOSTIC_ALIASES);
        $truncated = count($aliases) > self::MAXIMUM_DIAGNOSTIC_ALIASES;
        $ready = ! $truncated;

        foreach ($diagnosticAliases as $alias) {
            try {
                $this->resolver($alias);
            } catch (Throwable) {
                $ready = false;
            }
        }

        return [
            'aliases' => $diagnosticAliases,
            'ready' => $ready,
            'registered' => count($aliases),
            'truncated' => $truncated,
        ];
    }

    /**
     * Resolve one registered resource implementation.
     */
    private function resolver(string $alias): CommentMentionResourceResolver
    {
        $registered = $this->resources[$alias]['resolver'] ?? null;

        if ($registered === null) {
            throw new InvalidCommentMutationException(
                'Comment document contains an unregistered mention resource.',
            );
        }

        try {
            $resolver = is_string($registered)
                ? $this->container->make($registered)
                : $registered;
        } catch (Throwable) {
            throw new InvalidArgumentException(
                'The configured comment mention resource resolver is invalid.',
            );
        }

        if (! $resolver instanceof CommentMentionResourceResolver) {
            throw new InvalidArgumentException(
                'The configured comment mention resource resolver is invalid.',
            );
        }

        return $resolver;
    }

    /**
     * Resolve one alias-local batch through its registered implementation.
     *
     * @param  list<string>  $ids
     * @return Collection<int, CommentMentionResourceData>
     */
    private function resolvedBatch(
        string $alias,
        CommentMentionContext $context,
        array $ids,
    ): Collection {
        $resolver = $this->resolver($alias);

        try {
            return $resolver->resolve($context, $ids);
        } catch (Throwable) {
            throw new InvalidCommentMutationException(
                'Comment mention resolution returned an invalid resource batch.',
            );
        }
    }

    /**
     * Normalize and validate one bounded opaque identifier list.
     *
     * @param  array<array-key, mixed>  $ids
     * @return list<string>
     */
    private function boundedIds(array $ids): array
    {
        $maximum = min(100, CommentsConfiguration::positiveInteger(
            'comments.mentions.maximum_batch_size',
            100,
        ));

        if ($ids === []) {
            throw new InvalidCommentMutationException(
                'Comment mention resolution batch is outside the configured bounds.',
            );
        }

        $bounded = [];

        foreach ($ids as $id) {
            if (! is_string($id)
                || ! mb_check_encoding($id, 'UTF-8')
                || preg_match('/\S/u', $id) !== 1
                || mb_strlen($id) > 255) {
                throw new InvalidCommentMutationException(
                    'Comment mention resolution batch contains an invalid identifier.',
                );
            }

            if (! in_array($id, $bounded, true)) {
                $bounded[] = $id;
            }
        }

        if (count($bounded) > $maximum) {
            throw new InvalidCommentMutationException(
                'Comment mention resolution batch is outside the configured bounds.',
            );
        }

        return $bounded;
    }

    /**
     * Validate every custom resolver value before reading its DTO properties.
     *
     * @param  iterable<mixed>  $resolved
     * @param  list<string>|null  $requestedIds
     * @return array<string, CommentMentionResourceData>
     */
    private function validateResolved(
        iterable $resolved,
        ?array $requestedIds,
        string $kind,
    ): array {
        $byId = [];

        foreach ($resolved as $resource) {
            if (! $resource instanceof CommentMentionResourceData
                || ($requestedIds !== null && ! in_array($resource->id, $requestedIds, true))
                || isset($byId[$resource->id])) {
                throw new InvalidCommentMutationException(
                    "Comment mention resolution returned an invalid {$kind} batch.",
                );
            }

            $byId[$resource->id] = $resource;
        }

        return $byId;
    }

    /**
     * Validate a unique resource alias before registration.
     */
    private function assertNewAlias(string $alias): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $alias) !== 1) {
            throw new InvalidArgumentException('Comment mention resource aliases are invalid.');
        }

        if (isset($this->resources[$alias])) {
            throw new InvalidArgumentException('Comment mention resource aliases must be unique.');
        }
    }

    /**
     * Validate one allowlisted field list.
     *
     * @param  array<array-key, mixed>  $fields
     * @return list<string>
     */
    private function fields(array $fields, string $kind): array
    {
        if (! array_is_list($fields)) {
            throw new InvalidArgumentException(
                'Declarative comment mention fields must be a list.',
            );
        }

        if (count($fields) > self::MAXIMUM_DECLARATIVE_FIELDS) {
            throw new InvalidArgumentException(
                "Declarative comment mention {$kind} fields exceed the package boundary.",
            );
        }

        $validated = [];

        foreach ($fields as $field) {
            if (! is_string($field)
                || strlen($field) > self::MAXIMUM_DECLARATIVE_FIELD_NAME_BYTES
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) !== 1
                || in_array($field, $validated, true)) {
                throw new InvalidArgumentException(
                    'Declarative comment mention fields are invalid.',
                );
            }

            $validated[] = $field;
        }

        return $validated;
    }
}
