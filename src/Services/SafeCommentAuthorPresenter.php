<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Database\Eloquent\Collection;
use Nvl\Comments\Contracts\CommentAuthorPresenter;
use Nvl\Comments\Data\CommentAuthorData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;

/**
 * Presents anonymous or opaque authors without resolving consumer user models.
 */
final class SafeCommentAuthorPresenter implements CommentAuthorPresenter
{
    /**
     * Present safe placeholder authors without exposing stored actor identities.
     *
     * @param  Collection<int, Comment>  $comments
     * @return array<string, CommentAuthorData|null>
     */
    public function present(Collection $comments, CommentAudience $audience): array
    {
        $authors = [];

        foreach ($comments as $comment) {
            if ($comment->trashed() || $comment->getAttribute('anonymized_at') !== null) {
                $authors[$comment->id] = null;

                continue;
            }

            $authors[$comment->id] = $comment->actor_type === null || $comment->actor_id === null
                ? CommentAuthorData::anonymous()
                : CommentAuthorData::opaque();
        }

        return $authors;
    }
}
