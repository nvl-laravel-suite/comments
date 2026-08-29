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
 * Viewer-safe paragraph block projected from a stored rich document.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class CommentViewerDocumentBlockData extends Data
{
    use DataTransform;

    /**
     * Create one viewer-safe paragraph block.
     *
     * @param  list<array<string, string>>  $children
     */
    public function __construct(
        #[LiteralTypeScriptType("'paragraph'")]
        public readonly string $type,
        #[LiteralTypeScriptType('Array<Nvl.Comments.Data.CommentViewerDocumentNodeData>')]
        public readonly array $children,
    ) {}
}
