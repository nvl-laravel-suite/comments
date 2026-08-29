<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Nvl\Comments\Contracts\CommentMetadataSchema;
use Nvl\Comments\Definitions\CommentMetadataField;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentMetadataValueType;

/**
 * Supplies representative registered comment metadata for package tests.
 */
final class TestCommentMetadataSchema implements CommentMetadataSchema
{
    /**
     * Return the stable workflow metadata namespace.
     */
    public function namespace(): string
    {
        return 'workflow';
    }

    /**
     * Return scalar workflow metadata fields spanning every supported type.
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
                queryable: true,
                visibleTo: [CommentAudience::Member, CommentAudience::Management],
                maximumStringLength: 64,
            ),
            new CommentMetadataField(
                name: 'sequence',
                storageKey: 'workflow_sequence',
                type: CommentMetadataValueType::Integer,
                nullable: false,
                mutable: true,
                queryable: true,
                visibleTo: [CommentAudience::Public],
            ),
            new CommentMetadataField(
                name: 'approved',
                storageKey: 'workflow_approved',
                type: CommentMetadataValueType::Boolean,
                nullable: false,
                mutable: true,
                queryable: true,
                visibleTo: [CommentAudience::Member],
            ),
            new CommentMetadataField(
                name: 'recipient',
                storageKey: 'recipient_user_id',
                type: CommentMetadataValueType::Uuid,
                nullable: true,
                mutable: false,
                queryable: true,
                visibleTo: [CommentAudience::Management],
            ),
            new CommentMetadataField(
                name: 'note',
                storageKey: 'workflow_note',
                type: CommentMetadataValueType::String,
                nullable: true,
                mutable: true,
                queryable: false,
                visibleTo: [CommentAudience::Public],
                maximumStringLength: 20,
            ),
        ];
    }
}
