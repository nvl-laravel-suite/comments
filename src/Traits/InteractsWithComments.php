<?php

declare(strict_types=1);

namespace Nvl\Comments\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nvl\Comments\Contracts\CommentBatchQueryScope;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Relations\StringMorphMany;
use Nvl\Comments\Services\CommentBatchQueryBuilder;

/**
 * Adds a string-key-compatible comment relationship to a model.
 *
 * @mixin Model
 *
 * @api
 *
 * @nvl-consumer-relation comments
 */
trait InteractsWithComments
{
    /**
     * Add a SQL-authorized nondeleted count while retaining host scopes and selections.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithNvlVisibleCommentCount(Builder $query, CommentActorData $actor, CommentAudience $audience = CommentAudience::Public, ?CommentBatchQueryScope $policy = null): Builder
    {
        return app(CommentBatchQueryBuilder::class)->withVisibleCount($query, $actor, $audience, $policy ?? app(CommentBatchQueryScope::class));
    }

    /**
     * @return MorphMany<Comment, $this>
     *
     * @internal
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
