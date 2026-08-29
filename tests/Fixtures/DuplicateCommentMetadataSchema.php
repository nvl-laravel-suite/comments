<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Nvl\Comments\Contracts\CommentMetadataSchema;
use Nvl\Comments\Definitions\CommentMetadataField;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentMetadataValueType;

/**
 * Deliberately collides with the workflow schema's storage ownership.
 */
final class DuplicateCommentMetadataSchema implements CommentMetadataSchema
{
    /**
     * Return a distinct namespace so storage ownership is the only collision.
     */
    public function namespace(): string
    {
        return 'duplicate';
    }

    /**
     * Return one field that reuses another schema's storage key.
     *
     * @return list<CommentMetadataField>
     */
    public function fields(): array
    {
        return [
            new CommentMetadataField(
                name: 'event',
                storageKey: 'workflow_event',
                type: CommentMetadataValueType::String,
                nullable: false,
                mutable: true,
                queryable: false,
                visibleTo: [CommentAudience::Member],
            ),
        ];
    }
}
