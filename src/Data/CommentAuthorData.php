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
 * Audience-safe author presentation without a stored polymorphic identity.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class CommentAuthorData extends Data
{
    use DataTransform;

    public function __construct(
        public readonly ?string $key,
        public readonly ?string $displayName,
        public readonly ?string $avatarUrl,
        public readonly ?string $label,
        public readonly bool $anonymous,
    ) {}

    /**
     * Create the package-safe anonymous author representation.
     */
    public static function anonymous(): self
    {
        return new self(
            key: null,
            displayName: null,
            avatarUrl: null,
            label: null,
            anonymous: true,
        );
    }

    /**
     * Create the package-safe opaque identified-author representation.
     */
    public static function opaque(): self
    {
        return new self(
            key: null,
            displayName: null,
            avatarUrl: null,
            label: null,
            anonymous: false,
        );
    }
}
