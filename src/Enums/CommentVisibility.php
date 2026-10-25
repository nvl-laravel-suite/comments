<?php

declare(strict_types=1);

namespace Nvl\Comments\Enums;

/**
 * Audience boundary for an approved comment.
 *
 * @api
 */
enum CommentVisibility: string
{
    case Public = 'public';
    case Private = 'private';
    case Internal = 'internal';
}
