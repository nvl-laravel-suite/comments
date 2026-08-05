<?php

declare(strict_types=1);

namespace Nvl\Comments\Data\Mutations;

use Illuminate\Validation\Rule;
use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Validated contract for a root comment or reply.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class CreateCommentData extends Data
{
    use DataTransform;

    /**
     * Create a comment mutation contract with backend-owned defaults.
     *
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $body,
        #[TypeScriptOptional]
        public readonly CommentFormat $format = CommentFormat::Plain,
        #[TypeScriptOptional]
        public readonly CommentVisibility $visibility = CommentVisibility::Public,
        #[TypeScriptOptional]
        public readonly ?string $locale = null,
        #[TypeScriptOptional]
        public readonly ?string $parentId = null,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Array<string>')]
        public readonly array $tags = [],
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $metadata = [],
        #[TypeScriptOptional]
        public readonly ?string $idempotencyKey = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1'],
            'format' => ['sometimes', Rule::enum(CommentFormat::class)],
            'visibility' => ['sometimes', Rule::enum(CommentVisibility::class)],
            'locale' => ['nullable', 'string', 'max:35'],
            'parentId' => ['nullable', 'uuid'],
            'tags' => ['array', 'list'],
            'tags.*' => ['required', 'string', 'max:64', 'distinct'],
            'metadata' => ['array'],
            'idempotencyKey' => ['nullable', 'uuid'],
        ];
    }
}
