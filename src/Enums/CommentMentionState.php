<?php

declare(strict_types=1);

namespace Nvl\Comments\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Describes whether a stored mention has an authorized live resource projection.
 */
#[TypeScript]
enum CommentMentionState: string
{
    case Resolved = 'resolved';
    case Missing = 'missing';
    case Restricted = 'restricted';
}
