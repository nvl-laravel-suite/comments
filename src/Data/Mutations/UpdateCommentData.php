<?php

declare(strict_types=1);

namespace Nvl\Comments\Data\Mutations;

use Illuminate\Validation\Rule;
use Nvl\Comments\Enums\CommentFormat;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Presence-aware editable-content patch with an optimistic-lock token.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class UpdateCommentData extends Data
{
    use DataTransform;

    /**
     * @param  list<string>|Optional  $tags
     * @param  array<string, mixed>|Optional  $metadata
     */
    public function __construct(
        public readonly string $body,
        public readonly int $expectedRevision,
        public readonly CommentFormat|Optional $format = new Optional,
        public readonly string|Optional|null $locale = new Optional,
        #[LiteralTypeScriptType('Array<string>')]
        public readonly array|Optional $tags = new Optional,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array|Optional $metadata = new Optional,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1'],
            'expectedRevision' => ['required', 'integer', 'min:1'],
            'format' => ['sometimes', Rule::enum(CommentFormat::class)],
            'locale' => ['sometimes', 'nullable', 'string', 'max:35'],
            'tags' => ['sometimes', 'array', 'list'],
            'tags.*' => ['required', 'string', 'max:64', 'distinct'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
