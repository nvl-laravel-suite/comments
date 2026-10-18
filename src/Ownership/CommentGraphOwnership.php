<?php

declare(strict_types=1);

namespace Nvl\Comments\Ownership;

use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentModelRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/** Checks canonical thread identities inside the actual source transaction. @internal */
final readonly class CommentGraphOwnership
{
    public function assertHierarchy(Comment $comment): void
    {
        if (! CommentsOwnership::declared()) {
            return;
        }
        foreach (['parent_id', 'root_id'] as $field) {
            $id = $comment->getAttribute($field);
            if ($id === null) {
                continue;
            }
            $parent = CommentModelRegistry::query()->withTrashed()->whereKey($id)->lockForUpdate()->first();
            if ($parent === null || $parent->commentable_type !== $comment->commentable_type || $parent->commentable_id !== $comment->commentable_id) {
                throw new OwnershipViolation('Comment hierarchy must retain its canonical target and owner.');
            }
        }
    }
}
