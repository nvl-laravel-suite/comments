<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves an API-safe target alias and string-compatible identifier.
 *
 * @api
 */
interface CommentTargetResolver
{
    public function alias(): string;

    public function resolve(string $identifier): ?Model;
}
