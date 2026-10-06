<?php

declare(strict_types=1);

namespace Nvl\Comments\Exceptions;

use Nvl\Comments\Enums\CommentsResponseCode;
use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Exceptions\ExceptionResponse;
use Nvl\Support\Traits\InteractsWithPackageFailure;
use RuntimeException;

/**
 * @api
 * Base package-domain exception.
 */
class CommentsException extends RuntimeException implements RespondableException
{
    use InteractsWithPackageFailure;

    /** Resolve the declared safe failure for this native hierarchy. */
    protected function exceptionResponse(): ExceptionResponse
    {
        return match (static::class) {
            CommentTargetNotFoundException::class => new ExceptionResponse('comments', CommentsResponseCode::CommentTargetNotFound, 404),
            CommentAttachmentDeliveryUnavailableException::class => new ExceptionResponse('comments', CommentsResponseCode::CommentAttachmentDeliveryUnavailable, 503),
            CommentMutationBusyException::class => new ExceptionResponse('comments', CommentsResponseCode::CommentMutationBusy, 409, headers: ['Retry-After' => '1']),
            CommentIdempotencyConflictException::class => new ExceptionResponse('comments', CommentsResponseCode::CommentIdempotencyConflict, 409),
            StaleCommentException::class => new ExceptionResponse('comments', CommentsResponseCode::StaleComment, 409),
            InvalidCommentLifecycleException::class => new ExceptionResponse('comments', CommentsResponseCode::InvalidCommentLifecycle, 422),
            CommentMutationLockConfigurationException::class => new ExceptionResponse('comments', CommentsResponseCode::InvalidConfiguration, 500),
            CommentBatchReadException::class => new ExceptionResponse('comments', CommentsResponseCode::BatchReadUnavailable, 500),
            default => new ExceptionResponse('comments', CommentsResponseCode::OperationFailed),
        };
    }
}
