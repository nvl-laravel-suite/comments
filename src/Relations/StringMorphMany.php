<?php

declare(strict_types=1);

namespace Nvl\Comments\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use InvalidArgumentException;
use LogicException;
use Nvl\Comments\Support\CommentIdentity;

/**
 * Morph-many relation whose foreign identifier is normalized to text.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<TRelatedModel, TDeclaringModel>
 */
final class StringMorphMany extends MorphMany
{
    public function addConstraints(): void
    {
        if (! self::$constraints) {
            return;
        }

        $identifier = $this->normalize($this->getParentKey());

        $this->getRelationQuery()
            ->where(
                $this->getRelated()->qualifyColumn('commentable_identity_hash'),
                $identifier === null
                    ? null
                    : CommentIdentity::pair($this->morphClass, $identifier),
            )
            ->whereNotNull($this->foreignKey);
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     */
    public function addEagerConstraints(array $models): void
    {
        $identifiers = array_map(
            fn (mixed $key): ?string => $this->normalize($key),
            $this->getKeys($models, $this->localKey),
        );
        $hashes = array_map(
            fn (string $identifier): string => CommentIdentity::pair(
                $this->morphClass,
                $identifier,
            ),
            array_values(array_filter(
                $identifiers,
                static fn (?string $key): bool => $key !== null,
            )),
        );
        $this->whereInEager(
            'whereIn',
            $this->getRelated()->qualifyColumn('commentable_identity_hash'),
            $hashes,
            $this->getRelationQuery(),
        );
    }

    /**
     * @param  array<int, string>|string  $columns
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceQuery(
        Builder $query,
        Builder $parentQuery,
        $columns = ['*'],
    ): Builder {
        $commentsConnection = $query->getModel()->getConnection()->getName();
        $targetConnection = $parentQuery->getModel()->getConnection()->getName();

        if ($commentsConnection !== $targetConnection) {
            throw new LogicException(
                'Comment relationship existence queries require targets and Comments to share one database connection; use the Comments read Actions for cross-connection targets.',
            );
        }

        $driver = $query->getModel()->getConnection()->getDriverName();
        $parentKey = TextColumnComparison::text(
            $query->getQuery()->getGrammar()->wrap($this->getQualifiedParentKeyName()),
            $driver,
        );
        $foreignKey = $query->getQuery()->getGrammar()->wrap($this->getExistenceCompareKey());
        $morphColumn = $query->getQuery()->getGrammar()->wrap(
            $query->qualifyColumn($this->getMorphType()),
        );

        return $query
            ->select($columns)
            ->whereRaw(new TextColumnComparison($parentKey, $foreignKey, $driver))
            ->whereRaw(
                TextColumnComparison::value($morphColumn, $driver),
                [$this->morphClass, $this->morphClass],
            );
    }

    /**
     * @param  TRelatedModel  $model
     */
    protected function setForeignAttributesForCreate(Model $model): void
    {
        $model->setAttribute($this->getForeignKeyName(), $this->normalize($this->getParentKey()));
        $model->setAttribute($this->getMorphType(), $this->morphClass);
        $identifier = $this->normalize($this->getParentKey());
        $model->setAttribute(
            'commentable_identity_hash',
            $identifier === null ? null : CommentIdentity::pair($this->morphClass, $identifier),
        );
        $this->applyInverseRelationToModel($model);
    }

    private function normalize(mixed $identifier): ?string
    {
        if ($identifier === null) {
            return null;
        }

        if (! is_int($identifier) && ! is_string($identifier)) {
            throw new InvalidArgumentException('Comment target identifiers must be integers or strings.');
        }

        return (string) $identifier;
    }
}
