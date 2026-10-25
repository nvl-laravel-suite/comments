<?php

declare(strict_types=1);

namespace Nvl\Comments\Enums;

/**
 * Stable audience boundaries used for comment authorization and projection.
 *
 * @api
 */
enum CommentAudience: string
{
    case Public = 'public';
    case Member = 'member';
    case Management = 'management';
}
