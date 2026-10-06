<?php

declare(strict_types=1);

namespace Nvl\Comments\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

/**
 * @api

 * Rejects a comment lifecycle transition that violates durable state invariants.
 */
final class InvalidCommentLifecycleException extends CommentsException implements ShouldntReport {}
