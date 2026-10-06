<?php

declare(strict_types=1);

namespace Nvl\Comments\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use InvalidArgumentException;
use Nvl\Comments\Enums\CommentsResponseCode;
use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Exceptions\ExceptionResponse;
use Nvl\Support\Traits\InteractsWithPackageFailure;

/**
 * @api

 * Raised when validated transport data violates a comment-domain invariant.
 */
final class InvalidCommentMutationException extends InvalidArgumentException implements RespondableException, ShouldntReport
{
    use InteractsWithPackageFailure;

    /** Resolve safe package response metadata. */
    protected function exceptionResponse(): ExceptionResponse
    {
        return new ExceptionResponse('comments', CommentsResponseCode::InvalidCommentMutation, 422);
    }
}
