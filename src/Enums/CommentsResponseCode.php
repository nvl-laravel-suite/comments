<?php

declare(strict_types=1);

namespace Nvl\Comments\Enums;

use Nvl\Support\Contracts\ResponseCode;

/** Stable public response discriminators for Comments.
 * @api
 */
enum CommentsResponseCode: string implements ResponseCode
{
    case OperationFailed = 'operation_failed';
    case CommentTargetNotFound = 'comment_target_not_found';
    case CommentAttachmentDeliveryUnavailable = 'comment_attachment_delivery_unavailable';
    case CommentMutationBusy = 'comment_mutation_busy';
    case CommentIdempotencyConflict = 'comment_idempotency_conflict';
    case StaleComment = 'stale_comment';
    case InvalidCommentLifecycle = 'invalid_comment_lifecycle';
    case InvalidCommentMutation = 'invalid_comment_mutation';
    case InvalidConfiguration = 'invalid_configuration';
    case BatchReadUnavailable = 'batch_read_unavailable';
}
