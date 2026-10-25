<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/**
 * Version-one viewer-safe rich document without stored opaque resource identifiers.
 *
 * @api
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
final class CommentViewerDocumentData extends Data
{
    use DataTransform;

    /**
     * Create one viewer-safe version-one rich document.
     *
     * @param  list<CommentViewerDocumentBlockData>  $blocks
     */
    public function __construct(
        public readonly int $version,
        public readonly array $blocks,
    ) {}
}
