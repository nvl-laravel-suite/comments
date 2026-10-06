<?php

declare(strict_types=1);

namespace Nvl\Comments\Integrations;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use InvalidArgumentException;
use Nvl\Comments\Contracts\CommentAttachmentLifecycle;
use Nvl\Comments\Contracts\CommentAttachmentProjection;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentAttachmentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;

/** Keeps base comment operations independent of an inactive attachment integration. */
final readonly class UnavailableCommentAttachments implements CommentAttachmentLifecycle, CommentAttachmentProjection
{
    /**
     * Return no attachment counts for an inactive integration.
     *
     * @param  Collection<int, Comment>  $comments
     * @return array<string, int>
     */
    public function counts(Collection $comments, Model $target, CommentActorData $actor, CommentAudience $audience): array
    {
        return [];
    }

    /**
     * Return no attachment DTOs for an inactive integration.
     *
     * @return SupportCollection<int, CommentAttachmentData>
     */
    public function attachments(Comment $comment, Model $target, CommentActorData $actor, CommentAudience $audience): SupportCollection
    {
        return new SupportCollection;
    }

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
    public function detach(string $id, Comment $comment): int
    {
        throw new InvalidArgumentException('Comment attachment cleanup requires the loaded Media provider.');
    }
}
