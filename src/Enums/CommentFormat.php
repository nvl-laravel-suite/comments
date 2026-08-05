<?php

declare(strict_types=1);

namespace Nvl\Comments\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Non-executable author content format.
 */
#[TypeScript]
enum CommentFormat: string
{
    case Plain = 'plain';
    case Markdown = 'markdown';
}
