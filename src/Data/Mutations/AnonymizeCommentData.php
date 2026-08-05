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
 * Audited optimistic-lock contract for terminal comment anonymization.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class AnonymizeCommentData extends Data
{
    use DataTransform;

    /**
     * Create a terminal comment anonymization mutation.
     */
    public function __construct(
        public readonly int $expectedRevision,
        public readonly string $reason,
    ) {}

    /**
     * Return the transport validation rules.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'expectedRevision' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }
}
