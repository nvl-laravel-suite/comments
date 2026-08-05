<?php

declare(strict_types=1);

namespace Nvl\Comments\Support;

use BackedEnum;
use InvalidArgumentException;

/**
 * Produces collation-independent fingerprints for persisted Comments identities.
 */
final class CommentIdentity
{
    private const string DOMAIN = "nvl-comments-identity-v1\0";

    /**
     * Fingerprint one exact type and identifier pair without normalizing bytes.
     */
    public static function pair(string $type, string $identifier): string
    {
        return hash('sha256', self::DOMAIN
            .pack('N', strlen($type)).$type
            .pack('N', strlen($identifier)).$identifier);
    }

    /**
     * Fingerprint one exact value within a named domain.
     */
    public static function value(string $domain, string|BackedEnum $value): string
    {
        $resolved = $value instanceof BackedEnum ? (string) $value->value : $value;

        return self::pair($domain, $resolved);
    }

    /**
     * Fingerprint a valid persisted comment actor, retaining null for non-identifying actors.
     */
    public static function actor(?string $type, ?string $identifier): ?string
    {
        if ($type === null && $identifier === null) {
            return null;
        }

        if ($type === 'system' && $identifier === null) {
            return null;
        }

        if ($type === null || $identifier === null) {
            throw new InvalidArgumentException(
                'Persisted comment actors must contain both a type and identifier.',
            );
        }

        return self::pair($type, $identifier);
    }

    private function __construct() {}
}
