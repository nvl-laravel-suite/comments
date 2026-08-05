<?php

declare(strict_types=1);

namespace Nvl\Comments\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Relations\StringMorphMany;

/**
 * Adds a string-key-compatible comment relationship to a model.
 *
 * @mixin Model
 */
trait InteractsWithComments
{
    /**
     * @return MorphMany<Comment, $this>
     */
    public function comments(): MorphMany
    {
        $related = new Comment;

        return new StringMorphMany(
            $related->newQuery(),
            $this,
            $related->qualifyColumn('commentable_type'),
            $related->qualifyColumn('commentable_id'),
            $this->getKeyName(),
        );
    }
}
