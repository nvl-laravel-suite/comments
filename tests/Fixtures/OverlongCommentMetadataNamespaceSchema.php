<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Nvl\Comments\Contracts\CommentMetadataSchema;
use Nvl\Comments\Definitions\CommentMetadataField;

/**
 * Supplies an overlong namespace for metadata storage-bound tests.
 */
final class OverlongCommentMetadataNamespaceSchema implements CommentMetadataSchema
{
    /**
     * Return a syntactically valid namespace exceeding its storage column.
     */
    public function namespace(): string
    {
        return 'a'.str_repeat('b', 100);
    }

    /**
     * Return no fields because namespace validation must fail first.
     *
     * @return list<CommentMetadataField>
     */
    public function fields(): array
    {
        return [];
    }
}
