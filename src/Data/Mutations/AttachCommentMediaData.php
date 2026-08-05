<?php

declare(strict_types=1);

namespace Nvl\Comments\Data\Mutations;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Media identifier requested for one comment attachment.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class AttachCommentMediaData extends Data
{
    use DataTransform;

    public function __construct(public readonly string $mediaId) {}

    /**
     * Return the transport validation rules.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'mediaId' => ['required', 'uuid'],
        ];
    }
}
