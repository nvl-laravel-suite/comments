<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use Nvl\Comments\Enums\CommentMentionState;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Viewer-safe projection of one immutable mention token and optional live resource.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class CommentMentionData extends Data
{
    use DataTransform;

    /**
     * Create one viewer-shaped mention projection.
     *
     * @param  array<string, string|int|float|bool|null>  $fields
     */
    public function __construct(
        public readonly string $tokenId,
        public readonly string $resourceAlias,
        public readonly CommentMentionState $state,
        public readonly string $labelSnapshot,
        public readonly ?string $resourceId,
        public readonly ?string $currentLabel,
        #[LiteralTypeScriptType('Record<string, string | number | boolean | null>')]
        public readonly array $fields,
        public readonly ?string $url,
    ) {}
}
