<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Nvl\Comments\Definitions\CommentMetadataField;

/**
 * Defines one stable namespace of application-owned comment metadata fields.
 */
interface CommentMetadataSchema
{
    /**
     * Return the stable snake-case or dot-delimited namespace.
     */
    public function namespace(): string;

    /**
     * Return the registered scalar fields owned by this schema.
     *
     * @return list<CommentMetadataField>
     */
    public function fields(): array;
}
