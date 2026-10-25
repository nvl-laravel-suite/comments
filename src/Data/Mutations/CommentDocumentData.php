<?php

declare(strict_types=1);

namespace Nvl\Comments\Data\Mutations;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/**
 * Versioned bounded rich-document mutation payload.
 *
 * @api
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
final class CommentDocumentData extends Data
{
    use DataTransform;

    /**
     * Create one versioned rich-document payload.
     *
     * @param  array<array-key, mixed>  $blocks
     */
    public function __construct(
        public readonly int $version,
        public readonly array $blocks,
    ) {}

    /**
     * Return the root validation contract while structural validation stays server-owned.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'in:1'],
            'blocks' => ['required', 'array', 'list', 'min:1'],
        ];
    }
}
