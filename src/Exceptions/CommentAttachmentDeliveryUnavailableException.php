<?php

declare(strict_types=1);

namespace Nvl\Comments\Exceptions;

/**
 * @api

 * Raised when attachment delivery cannot issue its required signed capability URLs.
 */
final class CommentAttachmentDeliveryUnavailableException extends CommentsException {}
