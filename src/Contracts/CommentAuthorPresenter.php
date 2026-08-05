<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Nvl\Comments\Data\CommentAuthorData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;

/**
 * Presents stored comment authors without exposing polymorphic actor identities.
 */
interface CommentAuthorPresenter
{
    /**
     * Present authors for a comment batch in one audience-safe operation.
     *
     * @param  Collection<int, Comment>  $comments
     * @return array<string, CommentAuthorData|null>
     */
    public function present(Collection $comments, CommentAudience $audience): array;
}
