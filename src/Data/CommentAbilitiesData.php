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
 * Viewer-specific comment abilities projected without policy implementation details.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class CommentAbilitiesData extends Data
{
    use DataTransform;

    public function __construct(
        public readonly bool $reply = false,
        public readonly bool $update = false,
        public readonly bool $delete = false,
        public readonly bool $restore = false,
        public readonly bool $anonymize = false,
        public readonly bool $react = false,
        public readonly bool $report = false,
        public readonly bool $attach = false,
        public readonly bool $detach = false,
        public readonly bool $viewHistory = false,
        public readonly bool $restoreRevision = false,
        public readonly bool $moderate = false,
    ) {}

    /**
     * Create a representation with every ability denied.
     */
    public static function none(): self
    {
        return new self;
    }
}
