<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Carries one audience-safe allowlist of registered metadata scalars.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class CommentMetadataProjectionData extends Data
{
    use DataTransform;

    /**
     * Create one namespaced metadata projection.
     *
     * @param  array<string, string|int|bool|null>  $values
     */
    public function __construct(
        public readonly string $namespace,
        #[LiteralTypeScriptType('Record<string, string | number | boolean | null>')]
        public readonly array $values,
    ) {}
}
