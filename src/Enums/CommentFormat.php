<?php

declare(strict_types=1);

namespace Nvl\Comments\Enums;

/**
 * Non-executable author content format.
 *
 * @api
 */
enum CommentFormat: string
{
    case Plain = 'plain';
    case Markdown = 'markdown';

    case RichText = 'rich_text';
}
