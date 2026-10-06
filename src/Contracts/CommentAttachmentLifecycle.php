<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Closure;
use Nvl\Comments\Models\Comment;

/** Preserves optional attachment cleanup and locking during comment anonymization. */
interface CommentAttachmentLifecycle
{
    /** Determine whether an enabled or historical attachment state requires cleanup. */
    public function stateRequired(Comment $comment): bool;

    /**
     * Return deterministic attachment asset identifiers, optionally holding database row locks.
     *
     * @return list<string>
     */
    public function ids(Comment $comment, bool $lock = false): array;

    /**
     * Run anonymization while the selected asset mutation locks are held.
     *
     * @param  list<string>  $ids
     * @param  Closure(): Comment  $callback
     */
    public function synchronize(array $ids, Closure $callback): Comment;

    /** Detach one asset through the selected integration's public mutation contract. */
    public function detach(string $id, Comment $comment): int;
}
