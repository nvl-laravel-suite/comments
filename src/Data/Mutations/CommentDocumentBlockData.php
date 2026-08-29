<?php

declare(strict_types=1);

namespace Nvl\Comments\Data\Mutations;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Exact paragraph block accepted by version-one rich mutations.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class CommentDocumentBlockData extends Data
{
    use DataTransform;

    /**
     * Create one paragraph block contract.
     *
     * @param  list<array<string, mixed>>  $children
     */
    public function __construct(
        #[LiteralTypeScriptType("'paragraph'")]
        public readonly string $type,
        #[LiteralTypeScriptType('Array<Nvl.Comments.Data.Mutations.CommentDocumentNodeData>')]
        public readonly array $children,
    ) {}
}
