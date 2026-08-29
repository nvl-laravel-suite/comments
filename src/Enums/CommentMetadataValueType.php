<?php

declare(strict_types=1);

namespace Nvl\Comments\Enums;

/**
 * Enumerates the scalar value types accepted by registered comment metadata.
 */
enum CommentMetadataValueType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Uuid = 'uuid';
}
