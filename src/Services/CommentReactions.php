<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Events\CommentReactionChanged;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentReaction;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/**
 * Idempotently enables or removes one configured reaction type.
 *
 * @api
 */
final readonly class CommentReactions
{
    public function __construct(
        private CommentAccessService $access,
        private CommentMutationLock $mutationLock,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
        private OwnershipBoundary $boundary,
        private DomainEventDispatcher $domainEvents,
    ) {}

    public function add(
        Comment|string $comment,
        string $type,
        Authenticatable|SystemActor $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): CommentReaction {
        $reaction = $this->set($comment, $type, true, CommentActor::from($actor), $audience);
        if (! $reaction instanceof CommentReaction) {
            throw new InvalidCommentMutationException('The comment reaction could not be added.');
        }

        return $reaction;
    }

    public function remove(
        Comment|string $comment,
        string $type,
        Authenticatable|SystemActor $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): void {
        $this->set($comment, $type, false, CommentActor::from($actor), $audience);
    }

    /**
     * Set one configured reaction to the actor's explicit desired state.
     */
    private function set(
        Comment|string $comment,
        string $type,
        bool $active,
        CommentActor $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): ?CommentReaction {
        $commentId = $comment instanceof Comment ? $comment->id : $comment;
        $allowed = config('nvl-comments.reactions.allowed', []);

        if (! mb_check_encoding($type, 'UTF-8')
            || preg_match('/\S/u', $type) !== 1
            || mb_strlen($type) > 64
            || ! is_array($allowed)
            || ! in_array($type, $allowed, true)) {
            throw new InvalidCommentMutationException(
                "Reaction type [{$type}] is not enabled.",
            );
        }

        if ($actor->type === null || $actor->id === null) {
            throw new AuthorizationException('Reactions require an identified actor.');
        }

        $actorIdentityHash = CommentIdentity::pair($actor->type, $actor->id);
        $typeHash = CommentIdentity::value('reaction-type', $type);

        return $this->mutationLock->execute(
            $commentId,
            fn (): ?CommentReaction => DB::connection((CommentModelRegistry::newInstance())->getConnectionName())
                ->transaction(function () use (
                    $active,
                    $actor,
                    $actorIdentityHash,
                    $audience,
                    $commentId,
                    $type,
                    $typeHash,
                ): ?CommentReaction {
                    $comment = $this->reads->resolveById(
                        $commentId,
                        $actor,
                        $audience,
                        CommentAbility::React,
                        withTrashed: false,
                        lockForUpdate: true,
                    );
                    $target = $this->targets->locate($comment);
                    $this->access->authorize(
                        CommentAbility::React,
                        $actor,
                        $comment,
                        $target,
                        $audience,
                        asNotFound: $audience !== CommentAudience::Management,
                    );
                    $reaction = $this->boundary->query(CommentReaction::query(), 'comments.reactions')->where([
                        'comment_id' => $comment->id,
                        'actor_identity_hash' => $actorIdentityHash,
                        'type_hash' => $typeHash,
                    ])->lockForUpdate()->first();

                    if ($reaction !== null
                        && (! hash_equals($reaction->actor_type, $actor->type)
                            || ! hash_equals($reaction->actor_id, $actor->id)
                            || ! hash_equals($reaction->type, $type))) {
                        throw new InvalidCommentMutationException(
                            'The stored reaction identity fingerprint is inconsistent.',
                        );
                    }
                    $changed = false;

                    if ($active && $reaction === null) {
                        $reaction = CommentRecordWriter::create(CommentReaction::class, [
                            'comment_id' => $comment->id,
                            'actor_type' => $actor->type,
                            'actor_id' => $actor->id,
                            'type' => $type,
                        ]);

                        if (! $reaction->exists) {
                            throw new InvalidCommentMutationException(
                                'The comment reaction could not be created.',
                            );
                        }

                        if ($comment->increment('reaction_count') !== 1) {
                            throw new InvalidCommentMutationException(
                                'The comment reaction counter could not be updated.',
                            );
                        }

                        $changed = true;
                    } elseif (! $active && $reaction !== null) {
                        if (! $reaction->delete()) {
                            throw new InvalidCommentMutationException(
                                'The comment reaction could not be removed.',
                            );
                        }

                        $reaction = null;
                        $this->boundary->query(CommentModelRegistry::query(), 'comments.comments')
                            ->whereKey($comment->id)
                            ->where('reaction_count', '>', 0)
                            ->decrement('reaction_count');
                        $changed = true;
                    }

                    if ($changed) {
                        $this->domainEvents->dispatch(new CommentReactionChanged(
                            $comment->id,
                            $type,
                            $active,
                            $actor,
                        ), $comment->getConnection());
                    }

                    return $reaction;
                }, attempts: CommentsConfiguration::positiveInteger(
                    'nvl-comments.transactions.attempts',
                    3,
                )),
        );
    }
}
