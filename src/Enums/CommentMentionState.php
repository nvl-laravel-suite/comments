<?php

declare(strict_types=1);

namespace Nvl\Comments\Enums;

/**
 * Describes whether a stored mention has an authorized live resource projection.
 *
 * @api
 */
enum CommentMentionState: string
{
    case Resolved = 'resolved';
    case Missing = 'missing';
    case Restricted = 'restricted';
}
