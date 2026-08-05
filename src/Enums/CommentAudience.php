<?php

declare(strict_types=1);

namespace Nvl\Comments\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Stable audience boundaries used for comment authorization and projection.
 */
#[TypeScript]
enum CommentAudience: string
{
    case Public = 'public';
    case Member = 'member';
    case Management = 'management';
}
