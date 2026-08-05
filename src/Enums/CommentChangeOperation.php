<?php

declare(strict_types=1);

namespace Nvl\Comments\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Stable durable operations announced by comment change events.
 */
#[TypeScript]
enum CommentChangeOperation: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case Anonymized = 'anonymized';
    case Moderated = 'moderated';
    case ReportReviewed = 'report_reviewed';
    case RevisionRestored = 'revision_restored';
}
