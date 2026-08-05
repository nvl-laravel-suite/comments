<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Events\CommentReactionChanged;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentReaction;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentMutationLock;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentsConfiguration;

/**
 * Idempotently enables or removes one configured reaction type.
 */
final readonly class SetCommentReactionAction
{
    public function __construct(
        private CommentAccessService $access,
        private CommentMutationLock $mutationLock,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
    ) {}

    /**
     * Set one configured reaction to the actor's explicit desired state.
     */
    public function execute(
        Comment|string $comment,
        string $type,
        bool $active,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): ?CommentReaction {
        $commentId = $comment instanceof Comment ? $comment->id : $comment;
        $allowed = config('comments.reactions.allowed', []);

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
            fn (): ?CommentReaction => DB::connection((new Comment)->getConnectionName())
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
                    $reaction = CommentReaction::query()->where([
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
                        $reaction = CommentReaction::query()->create([
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
                        Comment::query()
                            ->whereKey($comment->id)
                            ->where('reaction_count', '>', 0)
                            ->decrement('reaction_count');
                        $changed = true;
                    }

                    if ($changed) {
                        CommentReactionChanged::dispatch(
                            $comment->id,
                            $type,
                            $active,
                            $actor,
                        );
                    }

                    return $reaction;
                }, attempts: CommentsConfiguration::positiveInteger(
                    'comments.transactions.attempts',
                    3,
                )),
        );
    }
}
