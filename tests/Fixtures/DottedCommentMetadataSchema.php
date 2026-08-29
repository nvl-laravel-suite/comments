<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Nvl\Comments\Contracts\CommentMetadataSchema;
use Nvl\Comments\Definitions\CommentMetadataField;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentMetadataValueType;

/**
 * Supplies a multi-segment namespace for metadata round-trip tests.
 */
final class DottedCommentMetadataSchema implements CommentMetadataSchema
{
    /**
     * Return the stable multi-segment workflow namespace.
     */
    public function namespace(): string
    {
        return 'sales.workflow';
    }

    /**
     * Return one queryable field for namespace persistence tests.
     *
     * @return list<CommentMetadataField>
     */
    public function fields(): array
    {
        return [
            new CommentMetadataField(
                name: 'event',
                storageKey: 'sales_workflow_event',
                type: CommentMetadataValueType::String,
                nullable: false,
                mutable: true,
                queryable: true,
                visibleTo: [CommentAudience::Member],
                maximumStringLength: 64,
            ),
        ];
    }
}
