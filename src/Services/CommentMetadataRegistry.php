<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Nvl\Comments\Contracts\CommentMetadataSchema;
use Nvl\Comments\Data\CommentMetadataProjectionData;
use Nvl\Comments\Definitions\CommentMetadataField;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentMetadataValueType;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Support\CommentsConfiguration;

/**
 * Resolves registered metadata schemas and owns canonical scalar normalization.
 */
final class CommentMetadataRegistry
{
    /** @var array<string, CommentMetadataSchema> */
    private array $schemas = [];

    /** @var array<string, CommentMetadataField> */
    private array $fields = [];

    /** @var array<string, CommentMetadataField> */
    private array $storageFields = [];

    /**
     * Resolve and validate all configured metadata schemas once.
     */
    public function __construct(private readonly Container $container)
    {
        $this->registerConfiguredSchemas();
    }

    /**
     * Return all schemas keyed by namespace.
     *
     * @return array<string, CommentMetadataSchema>
     */
    public function schemas(): array
    {
        return $this->schemas;
    }

    /**
     * Return all fields keyed by their public namespace and field alias.
     *
     * @return array<string, CommentMetadataField>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Determine whether one top-level JSON storage key is registry-owned.
     */
    public function ownsStorageKey(string $storageKey): bool
    {
        return isset($this->storageFields[$storageKey]);
    }

    /**
     * Normalize and validate a complete metadata record for storage.
     *
     * @param  array<array-key, mixed>  $metadata
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    public function normalize(array $metadata, ?array $existing = null): array
    {
        $registeredCount = 0;
        $normalized = [];

        foreach ($metadata as $storageKey => $value) {
            if (! is_string($storageKey)) {
                throw new InvalidCommentMutationException(
                    'Comment metadata must use string keys.',
                );
            }

            $field = $this->storageFields[$storageKey] ?? null;

            if (! $field instanceof CommentMetadataField) {
                if (config('comments.metadata.strict', false) === true) {
                    throw new InvalidCommentMutationException(
                        'Comment metadata contains an unregistered field.',
                    );
                }

                $normalized[$storageKey] = $this->canonicalValue($value);

                continue;
            }

            $registeredCount++;
            $normalizedValue = $this->normalizeFieldValue($field, $value);

            if ($existing !== null
                && ! $field->mutable
                && array_key_exists($storageKey, $existing)
                && $this->normalizeFieldValue($field, $existing[$storageKey]) !== $normalizedValue) {
                throw new InvalidCommentMutationException(
                    'Comment metadata contains an immutable field change.',
                );
            }

            $normalized[$storageKey] = $normalizedValue;
        }

        if ($existing !== null) {
            foreach ($this->storageFields as $storageKey => $field) {
                if (! $field->mutable
                    && array_key_exists($storageKey, $existing)
                    && ! array_key_exists($storageKey, $metadata)) {
                    throw new InvalidCommentMutationException(
                        'Comment metadata contains an immutable field change.',
                    );
                }
            }
        }

        $maximumFields = min(100, CommentsConfiguration::positiveInteger(
            'comments.metadata.maximum_registered_fields',
            50,
        ));

        if ($registeredCount > $maximumFields) {
            throw new InvalidCommentMutationException(
                'Comment metadata exceeds the registered field limit.',
            );
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * Project registered values explicitly visible to one audience.
     *
     * @param  array<string, mixed>|null  $metadata
     * @return list<CommentMetadataProjectionData>
     */
    public function project(?array $metadata, CommentAudience $audience): array
    {
        if ($metadata === null || $metadata === []) {
            return [];
        }

        $projections = [];

        foreach ($this->schemas as $namespace => $schema) {
            $values = [];

            foreach ($schema->fields() as $field) {
                if (! in_array($audience, $field->visibleTo, true)
                    || ! array_key_exists($field->storageKey, $metadata)) {
                    continue;
                }

                try {
                    $values[$field->name] = $this->normalizeFieldValue(
                        $field,
                        $metadata[$field->storageKey],
                    );
                } catch (InvalidCommentMutationException) {
                    continue;
                }
            }

            if ($values !== []) {
                $projections[] = new CommentMetadataProjectionData($namespace, $values);
            }
        }

        return $projections;
    }

    /**
     * Return canonical queryable index rows for one metadata record.
     *
     * @param  array<string, mixed>|null  $metadata
     * @return list<array{schema_namespace: string, field_name: string, value_type: string, value_hash: string}>
     *
     * @throws InvalidCommentMutationException
     */
    public function indexRows(?array $metadata): array
    {
        if ($metadata === null) {
            return [];
        }

        $rows = [];

        foreach ($this->fields as $alias => $field) {
            if (! $field->queryable || ! array_key_exists($field->storageKey, $metadata)) {
                continue;
            }

            $value = $this->normalizeFieldValue($field, $metadata[$field->storageKey]);
            [$namespace, $fieldName] = $this->splitAlias($alias);
            $rows[] = [
                'schema_namespace' => $namespace,
                'field_name' => $fieldName,
                'value_type' => $this->valueType($field, $value),
                'value_hash' => $this->hashValue($field, $value),
            ];
        }

        return $rows;
    }

    /**
     * Normalize bounded equality criteria into hash-only index predicates.
     *
     * @param  array<string, string|int|bool|null>  $criteria
     * @return list<array{schema_namespace: string, field_name: string, value_type: string, value_hash: string}>
     */
    public function selectorRows(array $criteria): array
    {
        $rows = [];

        foreach ($criteria as $alias => $value) {
            $field = $this->fields[$alias] ?? null;

            if (! $field instanceof CommentMetadataField || ! $field->queryable) {
                throw new InvalidArgumentException(
                    'Comment metadata selectors require a registered queryable field.',
                );
            }

            $normalized = $this->normalizeFieldValue($field, $value);
            [$namespace, $fieldName] = $this->splitAlias($alias);
            $rows[] = [
                'schema_namespace' => $namespace,
                'field_name' => $fieldName,
                'value_type' => $this->valueType($field, $normalized),
                'value_hash' => $this->hashValue($field, $normalized),
            ];
        }

        return $rows;
    }

    /**
     * Determine whether a stable metadata digest key can be resolved.
     */
    public function hasStableDigestKey(): bool
    {
        try {
            $this->keyBytes();

            return true;
        } catch (LogicException) {
            return false;
        }
    }

    /**
     * Recursively sort legacy maps without changing list order.
     */
    public function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalValue(...), $value);
        }

        ksort($value);

        return array_map($this->canonicalValue(...), $value);
    }

    /**
     * Resolve configured schema classes and reject ownership collisions.
     */
    private function registerConfiguredSchemas(): void
    {
        $configured = config('comments.metadata.schemas', []);

        if (! is_array($configured) || ! array_is_list($configured)) {
            throw new InvalidArgumentException('comments.metadata.schemas must be a list.');
        }

        foreach ($configured as $schemaClass) {
            if (! is_string($schemaClass)) {
                throw new InvalidArgumentException('Every comment metadata schema is invalid.');
            }

            $schema = $this->container->make($schemaClass);

            if (! $schema instanceof CommentMetadataSchema) {
                throw new InvalidArgumentException(
                    'Every comment metadata schema must implement CommentMetadataSchema.',
                );
            }

            $namespace = $schema->namespace();

            if (strlen($namespace) > 100
                || preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/', $namespace) !== 1) {
                throw new InvalidArgumentException(
                    'Comment metadata schema namespaces must use snake case and dots.',
                );
            }

            if (isset($this->schemas[$namespace])) {
                throw new InvalidArgumentException('Comment metadata schema namespaces must be unique.');
            }

            $fields = $this->schemaFields($schema);

            if (! is_array($fields) || ! array_is_list($fields)) {
                throw new InvalidArgumentException('Comment metadata schema fields must be a list.');
            }

            foreach ($fields as $field) {
                if (! $field instanceof CommentMetadataField) {
                    throw new InvalidArgumentException('Every comment metadata field is invalid.');
                }

                $alias = "{$namespace}.{$field->name}";

                if (isset($this->fields[$alias])) {
                    throw new InvalidArgumentException('Comment metadata field aliases must be unique.');
                }

                if (isset($this->storageFields[$field->storageKey])) {
                    throw new InvalidArgumentException(
                        'Comment metadata storage key ownership must be unique.',
                    );
                }

                $this->fields[$alias] = $field;
                $this->storageFields[$field->storageKey] = $field;
            }

            $this->schemas[$namespace] = $schema;
        }
    }

    /**
     * Read a schema field declaration without trusting its PHPDoc at runtime.
     */
    private function schemaFields(CommentMetadataSchema $schema): mixed
    {
        return $schema->fields();
    }

    /**
     * Split one public alias after its complete dotted namespace.
     *
     * @return array{string, string}
     */
    private function splitAlias(string $alias): array
    {
        $separator = strrpos($alias, '.');

        if ($separator === false) {
            throw new LogicException('A registered metadata alias is invalid.');
        }

        return [substr($alias, 0, $separator), substr($alias, $separator + 1)];
    }

    /**
     * Normalize one registered scalar without disclosing rejected values.
     */
    private function normalizeFieldValue(CommentMetadataField $field, mixed $value): string|int|bool|null
    {
        if ($value === null) {
            if ($field->nullable) {
                return null;
            }

            throw new InvalidCommentMutationException(
                'Comment metadata contains an invalid registered value.',
            );
        }

        return match ($field->type) {
            CommentMetadataValueType::String => is_string($value)
                && mb_check_encoding($value, 'UTF-8')
                && ($field->maximumStringLength === null
                    || mb_strlen($value) <= $field->maximumStringLength)
                    ? $value
                    : $this->invalidValue(),
            CommentMetadataValueType::Integer => is_int($value)
                ? $value
                : $this->invalidValue(),
            CommentMetadataValueType::Boolean => is_bool($value)
                ? $value
                : $this->invalidValue(),
            CommentMetadataValueType::Uuid => is_string($value) && Str::isUuid($value)
                ? mb_strtolower($value)
                : $this->invalidValue(),
        };
    }

    /**
     * Reject one invalid registered scalar without echoing its field or value.
     */
    private function invalidValue(): never
    {
        throw new InvalidCommentMutationException(
            'Comment metadata contains an invalid registered value.',
        );
    }

    /**
     * Return the actual scalar type persisted in the hash-only index.
     */
    private function valueType(CommentMetadataField $field, string|int|bool|null $value): string
    {
        return $value === null ? 'null' : $field->type->value;
    }

    /**
     * Hash one normalized scalar with a domain-separated keyed digest.
     */
    private function hashValue(CommentMetadataField $field, string|int|bool|null $value): string
    {
        $type = $this->valueType($field, $value);
        $normalized = match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            default => $value,
        };

        return hash_hmac(
            'sha256',
            "nvl-comments-metadata-v1\0{$type}\0{$normalized}",
            $this->keyBytes(),
        );
    }

    /**
     * Resolve the metadata, idempotency, or application digest key in order.
     */
    private function keyBytes(): string
    {
        $configured = config('comments.metadata.digest_key');

        if (! is_string($configured) || $configured === '') {
            $configured = config('comments.idempotency.digest_key');
        }

        if (! is_string($configured) || $configured === '') {
            $configured = config('app.key');
        }

        if (! is_string($configured) || $configured === '') {
            throw new LogicException(
                'Comment metadata requires a stable metadata, idempotency, or application key.',
            );
        }

        if (! str_starts_with($configured, 'base64:')) {
            return $configured;
        }

        $decoded = base64_decode(mb_substr($configured, 7), true);

        if (! is_string($decoded) || $decoded === '') {
            throw new LogicException('The configured comment metadata digest key is invalid.');
        }

        return $decoded;
    }
}
