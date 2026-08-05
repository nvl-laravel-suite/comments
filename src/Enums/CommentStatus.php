<?php

declare(strict_types=1);

namespace Nvl\Comments\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Moderation state of a comment.
 */
#[TypeScript]
enum CommentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Hidden = 'hidden';
    case Spam = 'spam';
}
