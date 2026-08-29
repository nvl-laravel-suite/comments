<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use InvalidArgumentException;
use Nvl\Comments\Enums\CommentMentionState;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Authorized server-owned suggestion returned to an editor.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class CommentMentionSuggestionData extends Data
{
    use DataTransform;

    /**
     * Create one bounded mention suggestion.
     *
     * @param  array<string, string|int|float|bool|null>  $fields
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        #[LiteralTypeScriptType('Record<string, string | number | boolean | null>')]
        public readonly array $fields = [],
        public readonly ?string $url = null,
    ) {}

    /**
     * Build one suggestion from an authorized live resource.
     */
    public static function fromResource(CommentMentionResourceData $resource): self
    {
        if ($resource->state !== CommentMentionState::Resolved
            || ! is_string($resource->label)) {
            throw new InvalidArgumentException(
                'Comment mention suggestions require resolved resource data.',
            );
        }

        return new self(
            id: $resource->id,
            label: $resource->label,
            fields: $resource->fields,
            url: $resource->url,
        );
    }
}
