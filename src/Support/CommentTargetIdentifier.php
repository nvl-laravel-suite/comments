<?php

declare(strict_types=1);

namespace Nvl\Comments\Support;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;

/**
 * Validates target identities and canonicalizes integer model keys.
 */
final class CommentTargetIdentifier
{
    /**
     * Return the canonical persisted identity of one rehydrated target.
     *
     * @return array{type: string, id: string}
     */
    public static function canonical(Model $target): array
    {
        $type = $target->getMorphClass();
        $identifier = self::lookupKey($target, $target->getKey());
        $resolved = (string) $identifier;

        self::assertText($type, 100, 'type');
        self::assertText($resolved, 255, 'identifier');

        return ['type' => $type, 'id' => $resolved];
    }

    /**
     * Normalize a caller-provided key for a canonical database lookup.
     */
    public static function lookupKey(Model $prototype, mixed $identifier): int|string
    {
        if ($prototype->getKeyType() === 'int') {
            return self::integerKey($identifier);
        }

        if (! is_int($identifier) && ! is_string($identifier)) {
            throw new InvalidCommentMutationException(
                'Comments require a persisted target with a scalar key.',
            );
        }

        $resolved = (string) $identifier;
        self::assertText($resolved, 255, 'identifier');

        return $resolved;
    }

    /**
     * Validate a persisted key before passing it to a typed target query.
     */
    public static function storedKey(Model $prototype, string $identifier): int|string
    {
        $key = self::lookupKey($prototype, $identifier);

        if ($prototype->getKeyType() === 'int' && (string) $key !== $identifier) {
            throw new InvalidCommentMutationException(
                'The persisted comment target identifier is not canonical.',
            );
        }

        return $key;
    }

    private static function assertText(string $value, int $maximum, string $label): void
    {
        if (! mb_check_encoding($value, 'UTF-8')
            || preg_match('/\S/u', $value) !== 1
            || mb_strlen($value) > $maximum) {
            throw new InvalidCommentMutationException(
                "Comment target {$label}s must be valid, non-blank UTF-8 with at most {$maximum} characters.",
            );
        }
    }

    private static function integerKey(mixed $identifier): int
    {
        if (is_int($identifier)) {
            return $identifier;
        }

        if (! is_string($identifier) || preg_match('/^-?[0-9]+$/D', $identifier) !== 1) {
            throw new InvalidCommentMutationException(
                'Integer comment target identifiers must contain only an optional sign and digits.',
            );
        }

        $negative = str_starts_with($identifier, '-');
        $digits = ltrim($negative ? substr($identifier, 1) : $identifier, '0');
        $digits = $digits === '' ? '0' : $digits;
        $maximum = $negative ? ltrim((string) PHP_INT_MIN, '-') : (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw new InvalidCommentMutationException(
                'The integer comment target identifier is outside the supported range.',
            );
        }

        $canonical = ($negative && $digits !== '0' ? '-' : '').$digits;

        return (int) $canonical;
    }

    private function __construct() {}
}
