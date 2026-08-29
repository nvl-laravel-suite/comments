<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use InvalidArgumentException;
use Nvl\Comments\Enums\CommentMentionState;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Authorized server-owned identity and label returned by a mention resolver.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class CommentMentionResourceData extends Data
{
    use DataTransform;

    /**
     * Create one resolved mention resource value.
     *
     * @param  array<string, string|int|float|bool|null>  $fields
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $label,
        #[LiteralTypeScriptType('Record<string, string | number | boolean | null>')]
        public readonly array $fields = [],
        public readonly ?string $url = null,
        public readonly CommentMentionState $state = CommentMentionState::Resolved,
    ) {
        if (! mb_check_encoding($this->id, 'UTF-8')
            || preg_match('/\S/u', $this->id) !== 1
            || mb_strlen($this->id) > 255) {
            throw new InvalidArgumentException(
                'Comment mention resource identifiers must be bounded non-blank UTF-8 strings.',
            );
        }

        if ($this->state === CommentMentionState::Resolved
            && ($this->label === null
                || ! mb_check_encoding($this->label, 'UTF-8')
                || preg_match('/\S/u', $this->label) !== 1
                || mb_strlen($this->label) > 255)) {
            throw new InvalidArgumentException(
                'Comment mention resource labels must be bounded non-blank UTF-8 strings.',
            );
        }

        if ($this->state !== CommentMentionState::Resolved
            && ($this->label !== null || $this->fields !== [] || $this->url !== null)) {
            throw new InvalidArgumentException(
                'Unavailable comment mention resources must not expose live data.',
            );
        }

        foreach ($this->fields as $field => $value) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) !== 1
                || ! self::isAllowedFieldValue($value)) {
                throw new InvalidArgumentException(
                    'Comment mention resource fields must be allowlisted scalar values.',
                );
            }
        }
    }

    /**
     * Determine whether one custom resolver field is JSON-safe scalar data.
     */
    private static function isAllowedFieldValue(mixed $value): bool
    {
        return is_scalar($value) || $value === null;
    }
}
