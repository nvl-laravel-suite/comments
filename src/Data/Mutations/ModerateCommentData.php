<?php

declare(strict_types=1);

namespace Nvl\Comments\Data\Mutations;

use Illuminate\Validation\Rule;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/**
 * Explicit moderation transition with concurrency protection.
 *
 * @api
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
final class ModerateCommentData extends Data
{
    use DataTransform;

    /**
     * Create an optimistic moderation transition contract.
     */
    public function __construct(
        public readonly CommentStatus $status,
        public readonly int $expectedRevision,
        public readonly ?string $reason = null,
        public readonly ?bool $pinned = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(CommentStatus::class)],
            'expectedRevision' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'pinned' => ['nullable', 'boolean'],
        ];
    }
}
