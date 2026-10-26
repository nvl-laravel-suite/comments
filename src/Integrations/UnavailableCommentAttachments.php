<?php

declare(strict_types=1);

namespace Nvl\Comments\Integrations;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Nvl\Comments\Contracts\CommentAttachmentLifecycle;
use Nvl\Comments\Models\Comment;
use Nvl\Support\Enums\SystemActor;

/** Keeps base comment operations independent of an inactive attachment integration. */
final readonly class UnavailableCommentAttachments implements CommentAttachmentLifecycle
{
    /** Permit ordinary anonymization when no attachment runtime is loaded. */
    public function stateRequired(Comment $comment): bool
    {
        return false;
    }

    /**
     * Return no asset identifiers when no attachment runtime is loaded.
     *
     * @return list<string>
     */
    public function ids(Comment $comment, bool $lock = false): array
    {
        return [];
    }

    /**
     * Run an ordinary comment mutation without asset locks.
     *
     * @param  list<string>  $ids
     * @param  Closure(): Comment  $callback
     */
    public function synchronize(array $ids, Closure $callback): Comment
    {
        return $callback();
    }

    /** Fail clearly when an unavailable attachment mutation is explicitly requested. */
    public function detach(Authenticatable|SystemActor $actor, string $id, Comment $comment): int
    {
        throw new InvalidArgumentException('Comment attachment cleanup requires the loaded Media provider.');
    }
}
