<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Database\Eloquent\Builder;
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
use Nvl\Comments\ValueObjects\CommentMentionContext;

/**
 * Resolves one declaratively registered Eloquent mention resource.
 */
final readonly class EloquentCommentMentionResourceResolver implements CommentMentionResourceResolver, ViewerIndependentCommentMentionResource
{
    /**
     * Create one allowlisted and authorization-scoped Eloquent resolver.
     *
     * @param  class-string<Model>  $modelClass
     * @param  list<string>  $searchableFields
     * @param  list<string>  $exposedFields
     */
    public function __construct(
        private string $modelClass,
        private array $searchableFields,
        private array $exposedFields,
        private string $labelField,
        private CommentMentionResourceAuthorization $authorization,
        private ?CommentMentionUrlResolver $urlResolver = null,
    ) {}

    /**
     * Resolve requested resources while concealing absent and unauthorized identities.
     *
     * @param  list<string>  $ids
     * @return Collection<int, CommentMentionResourceData>
     */
    public function resolve(CommentMentionContext $context, array $ids): Collection
    {
        $model = new $this->modelClass;
        $key = $model->getKeyName();
        $query = $model->newQuery()->whereKey($ids);
        $this->authorization->scope($query, $context);
        $authorized = $query
            ->select($this->selectedFields($key))
            ->get()
            ->keyBy(fn (Model $resource): string => $this->identifier($resource->getKey()));

        return collect($ids)->map(function (string $id) use (
            $authorized,
            $context,
        ): CommentMentionResourceData {
            $resource = $authorized->get($id);

            if (! $resource instanceof Model) {
                return new CommentMentionResourceData(
                    id: $id,
                    label: null,
                    state: CommentMentionState::Missing,
                );
            }

            return $this->resourceData($resource, $context);
        });
    }

    /**
     * Suggest authorized resources in deterministic label and identifier order.
     *
     * @return Collection<int, CommentMentionResourceData>
     */
    public function suggest(
        CommentMentionContext $context,
        string $query,
        int $limit,
    ): Collection {
        $model = new $this->modelClass;
        $key = $model->getKeyName();
        $builder = $model->newQuery();
        $this->authorization->scope($builder, $context);
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query);

        $builder->where(function (Builder $search) use ($escaped): void {
            foreach ($this->searchableFields as $position => $field) {
                $column = $search->getQuery()->getGrammar()->wrap($field);
                $sql = "{$column} LIKE ? ESCAPE '!'";
                $this->assertSafeSearchSql($sql);
                $method = $position === 0 ? 'whereRaw' : 'orWhereRaw';
                $search->{$method}($sql, ["%{$escaped}%"]);
            }
        });

        return $builder
            ->select($this->selectedFields($key))
            ->orderBy($this->labelField)
            ->orderBy($key)
            ->limit($limit)
            ->get()
            ->map(fn (Model $resource): CommentMentionResourceData => $this->resourceData(
                $resource,
                $context,
            ))
            ->values();
    }

    /**
     * Return the minimum selected field list for one live resource projection.
     *
     * @return list<string>
     */
    private function selectedFields(string $key): array
    {
        return array_values(array_unique([$key, $this->labelField, ...$this->exposedFields]));
    }

    /**
     * Build one validated live resource DTO from an allowlisted select.
     */
    private function resourceData(
        Model $resource,
        CommentMentionContext $context,
    ): CommentMentionResourceData {
        $label = $resource->getAttribute($this->labelField);

        if (! is_string($label) && ! is_int($label) && ! is_float($label)) {
            throw new InvalidCommentMutationException(
                'Comment mention resource labels must be scalar values.',
            );
        }

        $fields = [];

        foreach ($this->exposedFields as $field) {
            $value = $resource->getAttribute($field);

            if (! is_scalar($value) && $value !== null) {
                throw new InvalidCommentMutationException(
                    'Comment mention resource fields must be scalar values.',
                );
            }

            $fields[$field] = $value;
        }

        return new CommentMentionResourceData(
            id: $this->identifier($resource->getKey()),
            label: (string) $label,
            fields: $fields,
            url: $this->urlResolver?->resolve($resource, $context),
        );
    }

    /**
     * Normalize one database model identifier without accepting compound values.
     */
    private function identifier(mixed $identifier): string
    {
        if (! is_string($identifier) && ! is_int($identifier)) {
            throw new InvalidCommentMutationException(
                'Comment mention resource identifiers must be scalar values.',
            );
        }

        return (string) $identifier;
    }

    /**
     * Prove that grammar wrapping produced one quoted allowlisted identifier only.
     *
     * @phpstan-assert literal-string $sql
     */
    private function assertSafeSearchSql(string $sql): void
    {
        if (preg_match("/^[A-Za-z0-9_.\"`\\[\\]]+ LIKE \\? ESCAPE '!'$/D", $sql) !== 1) {
            throw new InvalidArgumentException(
                'Comment mention searchable fields produced unsafe SQL.',
            );
        }
    }
}
