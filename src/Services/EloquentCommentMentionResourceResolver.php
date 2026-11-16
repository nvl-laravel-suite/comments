<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Nvl\Comments\Contracts\CommentMentionResourceAuthorization;
use Nvl\Comments\Contracts\CommentMentionResourceResolver;
use Nvl\Comments\Contracts\CommentMentionUrlResolver;
use Nvl\Comments\Contracts\ViewerIndependentCommentMentionResource;
use Nvl\Comments\Enums\CommentMentionState;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Ownership\CommentsOwnership;
use Nvl\Comments\ValueObjects\CommentMentionContext;
use Nvl\Support\Contracts\TextMatcher;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/**
 * Resolves one declaratively registered Eloquent mention resource.
 *
 * @internal
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
        private TextMatcher $textMatcher,
        private ?CommentMentionUrlResolver $urlResolver = null,
        private ?OwnershipBoundary $boundary = null,
    ) {}

    /**
     * Resolve requested resources while concealing absent and unauthorized identities.
     *
     * @param  list<string>  $ids
     * @return Collection<int, array<string, mixed>>
     */
    public function resolve(CommentMentionContext $context, array $ids): Collection
    {
        $model = new $this->modelClass;
        $key = $model->getKeyName();
        // A malformed identifier can never match a UUID key and would fail the comparison on PostgreSQL.
        $keys = in_array(HasUuids::class, class_uses_recursive($model), true)
            ? array_values(array_filter($ids, Str::isUuid(...)))
            : $ids;
        $query = $this->resourceQuery($model, $context)->whereKey($keys);
        $this->authorization->scope($query, $context);
        $authorized = $query
            ->select($this->selectedFields($model, $key))
            ->get()
            ->keyBy(fn (Model $resource): string => $this->identifier($resource->getKey()));

        return collect($ids)->map(function (string $id) use (
            $authorized,
            $context,
        ): array {
            $resource = $authorized->get($id);

            if (! $resource instanceof Model) {
                return ['id' => $id, 'label' => null, 'fields' => [], 'url' => null, 'state' => CommentMentionState::Missing];
            }

            return $this->resourceData($resource, $context);
        });
    }

    /**
     * Suggest authorized resources in deterministic label and identifier order.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function suggest(
        CommentMentionContext $context,
        string $query,
        int $limit,
    ): Collection {
        $model = new $this->modelClass;
        $key = $model->getKeyName();
        $builder = $this->resourceQuery($model, $context);
        $this->authorization->scope($builder, $context);
        $builder->where(function (Builder $search) use ($query): void {
            foreach ($this->searchableFields as $field) {
                $qualified = str_contains($field, '.') ? $field : $search->qualifyColumn($field);
                $this->textMatcher->contains($search->getQuery(), $qualified, $query, boolean: 'or');
            }
        });

        return $builder
            ->select($this->selectedFields($model, $key))
            ->orderBy($builder->qualifyColumn($this->labelField))
            ->orderBy($builder->qualifyColumn($key))
            ->limit($limit)
            ->get()
            ->map(fn (Model $resource): array => $this->resourceData(
                $resource,
                $context,
            ))
            ->values();
    }

    /** @return Builder<Model> */
    private function resourceQuery(Model $model, CommentMentionContext $context): Builder
    {
        $query = $model->newQuery();
        if (! CommentsOwnership::declared()) {
            return $query;
        }

        return ($this->boundary ?? throw new InvalidCommentMutationException(
            'Ownership boundary is required for owned mention resources.',
        ))->query($query, CommentsOwnership::resource($model));
    }

    /**
     * Return the minimum selected field list for one live resource projection.
     *
     * @return list<string>
     */
    private function selectedFields(Model $model, string $key): array
    {
        $fields = [$key, $this->labelField, ...$this->exposedFields];

        if (CommentsOwnership::declared() && config('nvl-ownership.enabled') === true) {
            $definition = app(OwnershipResourceRegistry::class)->forModel($model);
            if ($definition->ownership->strategy !== 'unscoped') {
                $fields[] = 'owner_scope_key';
                if ($definition->ownership->strategy === 'column' && is_string($definition->ownership->foreignKey)) {
                    $fields[] = $definition->ownership->foreignKey;
                } elseif ($definition->ownership->strategy === 'captured') {
                    $fields[] = 'owner_reference';
                }
            }
        }

        return array_values(array_unique(array_map(
            static fn (string $field): string => str_contains($field, '.') ? $field : $model->qualifyColumn($field),
            $fields,
        )));
    }

    /**
     * Build one validated live resource DTO from an allowlisted select.
     *
     * @return array<string, mixed>
     */
    private function resourceData(
        Model $resource,
        CommentMentionContext $context,
    ): array {
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

        return [
            'id' => $this->identifier($resource->getKey()),
            'label' => (string) $label,
            'fields' => $fields,
            'url' => $this->urlResolver?->resolve($resource, $context),
            'state' => CommentMentionState::Resolved,
        ];
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
}
