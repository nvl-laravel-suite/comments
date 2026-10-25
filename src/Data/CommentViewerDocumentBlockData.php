<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/**
 * Viewer-safe paragraph block projected from a stored rich document.
 *
 * @api
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
final class CommentViewerDocumentBlockData extends Data
{
    use DataTransform;

    /**
     * Create one viewer-safe paragraph block.
     *
     * @param  list<array<string, string>>  $children
     */
    public function __construct(
        public readonly string $type,
        public readonly array $children,
    ) {}
}
