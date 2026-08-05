<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Comments\Exceptions\CommentTargetNotFoundException;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentTargetIdentifier;

/**
 * Resolves a comment's canonical target on the target model's own connection.
 */
final class CommentTargetLocator
{
    /**
     * Locate the live target referenced by a persisted comment.
     */
    public function locate(Comment $comment): Model
    {
        $modelClass = Relation::getMorphedModel($comment->commentable_type)
            ?? $comment->commentable_type;

        if (! is_a($modelClass, Model::class, true)) {
            throw CommentTargetNotFoundException::forIdentifier(
                $comment->commentable_type,
                $comment->commentable_id,
            );
        }

        $prototype = new $modelClass;

        try {
            $lookupKey = CommentTargetIdentifier::storedKey(
                $prototype,
                $comment->commentable_id,
            );
        } catch (InvalidCommentMutationException) {
            throw CommentTargetNotFoundException::forIdentifier(
                $comment->commentable_type,
                $comment->commentable_id,
            );
        }

        $target = $prototype->newQuery()->find($lookupKey);

        if (! $target instanceof Model) {
            throw CommentTargetNotFoundException::forIdentifier(
                $comment->commentable_type,
                $comment->commentable_id,
            );
        }

        $canonical = CommentTargetIdentifier::canonical($target);
        $fingerprint = CommentIdentity::pair($canonical['type'], $canonical['id']);

        if (! hash_equals($comment->commentable_type, $canonical['type'])
            || ! hash_equals($comment->commentable_id, $canonical['id'])
            || ! hash_equals($comment->commentable_identity_hash, $fingerprint)) {
            throw CommentTargetNotFoundException::forIdentifier(
                $comment->commentable_type,
                $comment->commentable_id,
            );
        }

        return $target;
    }
}
