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
 * Explicit desired state for one configured comment reaction.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class SetCommentReactionData extends Data
{
    use DataTransform;

    public function __construct(
        public readonly string $type,
        public readonly bool $active,
    ) {}

    /**
     * Return the transport validation rules.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'type' => ['required', 'string', 'max:64'],
            'active' => ['required', 'boolean'],
        ];
    }
}
