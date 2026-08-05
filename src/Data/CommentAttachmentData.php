<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Sanitized attachment association exposed after Comments and Media authorization.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class CommentAttachmentData extends Data
{
    use DataTransform;

    public function __construct(
        public readonly string $associationId,
        public readonly string $kind,
        public readonly string $name,
        public readonly ?string $mimeType,
        public readonly int $size,
        public readonly ?string $title,
        public readonly ?string $alt,
        public readonly string $assetUrl,
        public readonly string $thumbnailUrl,
        public readonly bool $canRemove,
        public readonly ?string $createdAt,
    ) {}
}
