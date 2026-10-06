<?php

declare(strict_types=1);

namespace Nvl\Comments\Exceptions;

/** Unsupported SQL authorization or storage capabilities prevent a safe batch read. */
final class CommentBatchReadException extends CommentsException {}
