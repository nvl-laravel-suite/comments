<?php

declare(strict_types=1);

namespace Nvl\Comments\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

/**
 * @api

 * Signals that a concurrent comment mutation still owns the serialization lock.
 */
final class CommentMutationBusyException extends CommentsException implements ShouldntReport {}
