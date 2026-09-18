<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Comments\Exceptions\CommentTargetNotFoundException;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentTargetIdentifier;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantResourceRegistry;

/**
 * Resolves a comment's canonical target on the target model's own connection.
 */
final readonly class CommentTargetLocator
{
    public function __construct(
        private TenantBoundary $boundary,
        private TenantResourceRegistry $resources,
        private Repository $config,
    ) {}

    /**
     * Reload a supplied target on its declared connection before evaluating policy attributes.
     */
    public function reload(Model $target): Model
    {
        $prototype = new ($target::class);
        $lookupKey = CommentTargetIdentifier::lookupKey($prototype, $target->getKey());

        $query = $prototype->newQuery();
        if ($this->config->get('tenancy.enabled') === true) {
            $query = $this->boundary->query($query, $this->resources->forModel($prototype)->key);
        }

        return $query->find($lookupKey)
            ?? throw CommentTargetNotFoundException::forIdentifier(
                $prototype->getMorphClass(),
                (string) $lookupKey,
            );
    }

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

        $query = $prototype->newQuery();
        if ($this->config->get('tenancy.enabled') === true) {
            $query = $this->boundary->query($query, $this->resources->forModel($prototype)->key);
        }
        $target = $query->find($lookupKey);

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
