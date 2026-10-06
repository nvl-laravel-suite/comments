<?php

declare(strict_types=1);

namespace Nvl\Comments\Definitions\Tables;

use Nvl\Support\Config\PackageStorage;

/**
 * Validates configured package-owned table identifiers.
 */
final class CommentsTables
{
    public const string Comments = 'nvl_comments_comments';

    public const string Reactions = 'nvl_comments_reactions';

    public const string Revisions = 'nvl_comments_revisions';

    public const string Reports = 'nvl_comments_reports';

    public const string MetadataValues = 'nvl_comments_metadata_values';

    public const string Mentions = 'nvl_comments_mentions';

    /**
     * Return one validated configured package table name.
     */
    public static function get(string $key): string
    {
        return PackageStorage::resolveTable('comments', $key);
    }

    private function __construct() {}
}
