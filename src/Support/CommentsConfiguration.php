<?php

declare(strict_types=1);

namespace Nvl\Comments\Support;

use Nvl\Comments\Definitions\Tables\CommentsTables;

/**
 * Typed access to Comments persistence and limits.
 */
final class CommentsConfiguration
{
    /**
     * Resolve the optional package database connection name.
     */
    public static function connection(): ?string
    {
        $connection = config('comments.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * Resolve one configured Comments table name.
     */
    public static function table(string $key): string
    {
        return CommentsTables::get($key);
    }

    /**
     * Resolve a configured positive integer or its positive fallback.
     *
     * @param  positive-int  $default
     * @return positive-int
     */
    public static function positiveInteger(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) && $value > 0 ? $value : $default;
    }

    private function __construct() {}
}
