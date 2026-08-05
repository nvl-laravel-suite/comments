<?php

declare(strict_types=1);

namespace Nvl\Comments\Definitions\Tables;

use InvalidArgumentException;

/**
 * Validates configured package-owned table identifiers.
 */
final class CommentsTables
{
    public static function get(string $key): string
    {
        $table = config("comments.tables.{$key}");

        if (! is_string($table)
            || strlen($table) > 63
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException("comments.tables.{$key} is invalid.");
        }

        return $table;
    }

    private function __construct() {}
}
