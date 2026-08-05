<?php

declare(strict_types=1);

namespace Nvl\Comments\Data\Mutations;

use Illuminate\Validation\Rule;
use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Explicit report review outcome.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class ResolveCommentReportData extends Data
{
    use DataTransform;

    public function __construct(
        public readonly CommentReportStatus $status,
        public readonly int $expectedRevision,
        public readonly string $resolution,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in([
                    CommentReportStatus::Resolved->value,
                    CommentReportStatus::Dismissed->value,
                ]),
            ],
            'expectedRevision' => ['required', 'integer', 'min:1'],
            'resolution' => ['required', 'string', 'max:4000'],
        ];
    }
}
