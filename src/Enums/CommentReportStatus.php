<?php

declare(strict_types=1);

namespace Nvl\Comments\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Review lifecycle of a comment report.
 */
#[TypeScript]
enum CommentReportStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}
