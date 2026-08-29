<?php

declare(strict_types=1);

namespace Nvl\Comments\Definitions;

use InvalidArgumentException;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentMetadataValueType;

/**
 * Describes one registered scalar metadata field and its safe capabilities.
 */
final readonly class CommentMetadataField
{
    /** @var list<CommentAudience> */
    public array $visibleTo;

    /**
     * Create one validated metadata field definition.
     *
     * @param  array<array-key, mixed>  $visibleTo
     */
    public function __construct(
        public string $name,
        public string $storageKey,
        public CommentMetadataValueType $type,
        public bool $nullable,
        public bool $mutable,
        public bool $queryable,
        array $visibleTo,
        public ?int $maximumStringLength = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $this->name) !== 1) {
            throw new InvalidArgumentException('Comment metadata field names must use snake case.');
        }

        if (preg_match('/^[a-z][a-z0-9_]*$/', $this->storageKey) !== 1) {
            throw new InvalidArgumentException('Comment metadata storage keys must use snake case.');
        }

        if (! array_is_list($visibleTo)
            || count($visibleTo) !== count(array_unique($visibleTo, SORT_REGULAR))) {
            throw new InvalidArgumentException('Comment metadata audiences must be a distinct list.');
        }

        $validatedAudiences = [];

        foreach ($visibleTo as $audience) {
            if (! $audience instanceof CommentAudience) {
                throw new InvalidArgumentException('Comment metadata audiences are invalid.');
            }

            $validatedAudiences[] = $audience;
        }

        $this->visibleTo = $validatedAudiences;

        if ($this->type !== CommentMetadataValueType::String
            && $this->maximumStringLength !== null) {
            throw new InvalidArgumentException(
                'Only string metadata fields may define a maximum string length.',
            );
        }

        if ($this->maximumStringLength !== null
            && ($this->maximumStringLength < 1 || $this->maximumStringLength > 65_536)) {
            throw new InvalidArgumentException(
                'Comment metadata string limits must be between 1 and 65536 characters.',
            );
        }
    }
}
