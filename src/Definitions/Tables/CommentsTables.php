<?php

declare(strict_types=1);

namespace Nvl\Comments\Definitions\Tables;

use InvalidArgumentException;

/**
 * Validates configured package-owned table identifiers.
 */
final class CommentsTables
{
    public const string Comments = 'comments';

    public const string Reactions = 'comment_reactions';

    public const string Revisions = 'comment_revisions';

    public const string Reports = 'comment_reports';

    public const string MetadataValues = 'comment_metadata_values';

    public const string Mentions = 'comment_mentions';

    /**
     * Return one validated configured package table name.
     */
    public static function get(string $key): string
    {
        $table = config("comments.tables.{$key}");

        if ($table === null && in_array($key, [self::MetadataValues, self::Mentions], true)) {
            $table = $key;
        }

        if (! is_string($table)
            || strlen($table) > 63
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException("comments.tables.{$key} is invalid.");
        }

        return $table;
    }

    private function __construct() {}
}
