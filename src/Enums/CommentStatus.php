<?php

declare(strict_types=1);

namespace Nvl\Comments\Enums;

/**
 * Moderation state of a comment.
 *
 * @api
 */
enum CommentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Hidden = 'hidden';
    case Spam = 'spam';
    case Flagged = 'flagged';
}
