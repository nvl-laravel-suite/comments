<?php

declare(strict_types=1);

namespace Nvl\Comments\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Audience boundary for an approved comment.
 */
#[TypeScript]
enum CommentVisibility: string
{
    case Public = 'public';
    case Private = 'private';
    case Internal = 'internal';
}
