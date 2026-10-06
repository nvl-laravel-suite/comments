<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\ResolveCommentReportData;
use Nvl\Comments\Models\CommentReport;

/**
 * Defines the supported resolve comment report workflow.
 *
 * @api
 */
interface ResolveCommentReportContract
{
    /**
     * Resolve or dismiss one report after moderator authorization.
     */
    public function execute(
        CommentReport|string $report,
        ResolveCommentReportData $data,
        CommentActorData $actor,
    ): CommentReport;
}
