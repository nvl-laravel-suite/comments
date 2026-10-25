<?php

declare(strict_types=1);

namespace Nvl\Comments\Enums;

/**
 * Review lifecycle of a comment report.
 *
 * @api
 */
enum CommentReportStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}
