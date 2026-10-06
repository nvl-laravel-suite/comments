<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nvl\Comments\Models\Comment;

/**
 * Marks a persisted Eloquent model as a comment thread target.
 *
 * @api
 */
interface HasComments
{
    /**
     * @return MorphMany<Comment, covariant Model>
     *
     * @internal
     */
    public function comments(): MorphMany;
}
