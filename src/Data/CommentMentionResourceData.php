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

    private const int MAXIMUM_FIELDS = 25;

    private const int MAXIMUM_FIELD_NAME_BYTES = 64;

    private const int MAXIMUM_FIELD_STRING_BYTES = 2_048;

    private const int MAXIMUM_URL_BYTES = 2_048;

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

        if (count($this->fields) > self::MAXIMUM_FIELDS) {
            throw new InvalidArgumentException(
                'Comment mention resource fields exceed the package boundary.',
            );
        }

        foreach ($this->fields as $field => $value) {
            if (! mb_check_encoding($field, 'UTF-8')
                || strlen($field) > self::MAXIMUM_FIELD_NAME_BYTES
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) !== 1
                || ! self::isAllowedFieldValue($value)) {
                throw new InvalidArgumentException(
                    'Comment mention resource fields must be allowlisted scalar values.',
                );
            }
        }

        if (! self::isAllowedUrl($this->url)) {
            throw new InvalidArgumentException(
                'Comment mention resource URLs must be bounded safe HTTP or relative URLs.',
            );
        }
    }

    /**
     * Determine whether one custom resolver field is JSON-safe scalar data.
     */
    private static function isAllowedFieldValue(mixed $value): bool
    {
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8')
                && strlen($value) <= self::MAXIMUM_FIELD_STRING_BYTES;
        }

        if (is_float($value)) {
            return is_finite($value);
        }

        return is_int($value) || is_bool($value) || $value === null;
    }

    /**
     * Determine whether one package-produced URL is bounded and non-executable.
     */
    private static function isAllowedUrl(?string $url): bool
    {
        if ($url === null) {
            return true;
        }

        if (! mb_check_encoding($url, 'UTF-8')
            || $url === ''
            || strlen($url) > self::MAXIMUM_URL_BYTES
            || str_contains($url, '\\')
            || preg_match('/[\x00-\x20\x7F]/u', $url) === 1) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme)
            && in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
