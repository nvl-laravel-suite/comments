<?php

declare(strict_types=1);

namespace Nvl\Comments\Data\Mutations;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/**
 * Validated report reason submitted by one actor.
 *
 * @api
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
final class ReportCommentData extends Data
{
    use DataTransform;

    /**
     * Create a report submission contract with optional details.
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?string $details = null,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:100'],
            'details' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
