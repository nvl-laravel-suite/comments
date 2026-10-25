<?php

declare(strict_types=1);

namespace Nvl\Comments\Data\Mutations;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/**
 * Exact paragraph block accepted by version-one rich mutations.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
final class CommentDocumentBlockData extends Data
{
    use DataTransform;

    /**
     * Create one paragraph block contract.
     *
     * @param  list<array<string, mixed>>  $children
     */
    public function __construct(
        public readonly string $type,
        public readonly array $children,
    ) {}
}
