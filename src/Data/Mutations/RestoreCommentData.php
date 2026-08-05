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
 * Optimistic-lock contract for restoring one deleted comment.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class RestoreCommentData extends Data
{
    use DataTransform;

    /**
     * Create a comment restoration mutation.
     */
    public function __construct(public readonly int $expectedRevision) {}

    /**
     * Return the transport validation rules.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'expectedRevision' => ['required', 'integer', 'min:1'],
        ];
    }
}
