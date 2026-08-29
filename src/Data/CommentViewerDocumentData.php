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
 * Version-one viewer-safe rich document without stored opaque resource identifiers.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class CommentViewerDocumentData extends Data
{
    use DataTransform;

    /**
     * Create one viewer-safe version-one rich document.
     *
     * @param  list<CommentViewerDocumentBlockData>  $blocks
     */
    public function __construct(
        #[LiteralTypeScriptType('1')]
        public readonly int $version,
        #[LiteralTypeScriptType('Array<Nvl.Comments.Data.CommentViewerDocumentBlockData>')]
        public readonly array $blocks,
    ) {}
}
