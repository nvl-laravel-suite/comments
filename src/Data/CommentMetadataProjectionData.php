<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/**
 * Carries one audience-safe allowlist of registered metadata scalars.
 *
 * @api
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
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
        public readonly array $values,
    ) {}
}
