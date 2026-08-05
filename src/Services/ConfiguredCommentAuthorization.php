<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Support\CommentIdentity;

/**
 * Conservative baseline policy suitable for a public discussion surface.
 *
 * Applications with tenant, role, private-thread, or moderator rules should bind
 * their own CommentAuthorization implementation.
 */
final class ConfiguredCommentAuthorization implements CommentAuthorization, CommentQueryScope
{
    /**
     * Determine whether package-safe defaults permit an operation.
     *
     * @param  array<string, mixed>  $context
     */
    public function allows(
        CommentAbility $ability,
        CommentActorData $actor,
        ?Comment $comment = null,
        ?Model $target = null,
        CommentAudience $audience = CommentAudience::Public,
        array $context = [],
    ): bool {
        if ($actor->system) {
            return true;
        }

        return match ($ability) {
            CommentAbility::List => $audience === CommentAudience::Public
                || ($audience === CommentAudience::Member && $actor->id !== null),
            CommentAbility::View => $this->isVisibleToActor($actor, $comment, $audience),
            CommentAbility::Create => $this->canCreate($actor, $audience, $context),
            CommentAbility::Reply => $this->canParticipate($actor, $audience)
                && $this->isVisibleToActor($actor, $comment, $audience)
                && ! $this->isAnonymized($comment),
            CommentAbility::Update => $this->isMutableAuthor($actor, $comment),
            CommentAbility::Delete => config(
                'comments.moderation.allow_author_delete',
                true,
            ) === true && $this->isMutableAuthor($actor, $comment),
            CommentAbility::Restore => config(
                'comments.moderation.allow_author_restore',
                true,
            ) === true && $this->isAuthor($actor, $comment)
                && $comment?->trashed() === true
                && ! $this->isAnonymized($comment),
            CommentAbility::Anonymize,
            CommentAbility::Moderate,
            CommentAbility::ViewIdentity => false,
            CommentAbility::React, CommentAbility::Report => $actor->id !== null
                && $this->isVisibleToActor($actor, $comment, $audience)
                && ! $this->isDeleted($comment)
                && ! $this->isAnonymized($comment),
            CommentAbility::Attach => config('comments.attachments.enabled', true) === true
                && $actor->id !== null
                && $this->isMutableAuthor($actor, $comment),
            CommentAbility::Detach => $actor->id !== null
                && $this->isMutableAuthor($actor, $comment),
            CommentAbility::ViewHistory, CommentAbility::RestoreRevision => $actor->id !== null
                && $this->isMutableAuthor($actor, $comment),
        };
    }

    /**
     * Apply trusted audience constraints before caller-controlled query changes.
     *
     * @param  Builder<Comment>  $query
     */
    public function scopeComments(
        Builder $query,
        CommentActorData $actor,
        Model $target,
        CommentAudience $audience,
        CommentAbility $ability,
    ): void {
        if ($actor->system) {
            return;
        }

        if ($audience === CommentAudience::Public
            && ! $this->usesViewerMutationScope($actor, $ability)) {
            $this->scopePublic($query);

            return;
        }

        if ($audience === CommentAudience::Management) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $query) use ($actor): void {
            $this->scopePublic($query);

            if ($actor->type === null || $actor->id === null) {
                return;
            }

            $query->orWhere(function (Builder $query) use ($actor): void {
                $query
                    ->where(
                        'actor_identity_hash',
                        CommentIdentity::pair($actor->type, $actor->id),
                    )
                    ->where(function (Builder $query): void {
                        $query
                            ->whereIn('status_hash', [
                                CommentIdentity::value('comment-status', CommentStatus::Pending),
                                CommentIdentity::value('comment-status', CommentStatus::Rejected),
                            ])
                            ->orWhere(
                                'visibility_hash',
                                CommentIdentity::value(
                                    'comment-visibility',
                                    CommentVisibility::Private,
                                ),
                            );
                    });
            });
        });
    }

    /**
     * Let identified public-route mutations resolve the actor's own review/private rows.
     */
    private function usesViewerMutationScope(
        CommentActorData $actor,
        CommentAbility $ability,
    ): bool {
        return $actor->type !== null
            && $actor->id !== null
            && ! in_array(
                $ability,
                [
                    CommentAbility::List,
                    CommentAbility::View,
                    CommentAbility::Create,
                ],
                true,
            );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function canCreate(
        CommentActorData $actor,
        CommentAudience $audience,
        array $context,
    ): bool {
        if (! $this->canParticipate($actor, $audience)) {
            return false;
        }

        $visibility = $context['visibility'] ?? CommentVisibility::Public;
        $visibility = $visibility instanceof CommentVisibility
            ? $visibility
            : CommentVisibility::tryFrom(is_string($visibility) ? $visibility : '');

        return match ($audience) {
            CommentAudience::Public => $visibility === CommentVisibility::Public,
            CommentAudience::Member => in_array(
                $visibility,
                [CommentVisibility::Public, CommentVisibility::Private],
                true,
            ),
            CommentAudience::Management => false,
        };
    }

    private function canParticipate(
        CommentActorData $actor,
        CommentAudience $audience,
    ): bool {
        if ($audience === CommentAudience::Management) {
            return false;
        }

        if ($actor->id !== null) {
            return true;
        }

        return $audience === CommentAudience::Public
            && config('comments.anonymous.enabled', false) === true;
    }

    private function isAuthor(CommentActorData $actor, ?Comment $comment): bool
    {
        return $comment !== null
            && $actor->type !== null
            && $actor->id !== null
            && hash_equals((string) $comment->actor_type, $actor->type)
            && hash_equals((string) $comment->actor_id, $actor->id);
    }

    private function isMutableAuthor(CommentActorData $actor, ?Comment $comment): bool
    {
        return $this->isAuthor($actor, $comment)
            && ! $this->isDeleted($comment)
            && ! $this->isAnonymized($comment);
    }

    private function isVisibleToActor(
        CommentActorData $actor,
        ?Comment $comment,
        CommentAudience $audience,
    ): bool {
        if ($this->isPubliclyVisible($comment)) {
            return true;
        }

        return $audience === CommentAudience::Member
            && $this->isAuthor($actor, $comment)
            && $this->isDefaultMemberRow($comment);
    }

    /**
     * Keep the baseline member expansion limited to own review/private rows.
     */
    private function isDefaultMemberRow(?Comment $comment): bool
    {
        return $comment !== null
            && (
                in_array(
                    $comment->status,
                    [CommentStatus::Pending, CommentStatus::Rejected],
                    true,
                )
                || $comment->visibility === CommentVisibility::Private
            );
    }

    private function isPubliclyVisible(?Comment $comment): bool
    {
        return $comment !== null
            && $comment->status === CommentStatus::Approved
            && $comment->visibility === CommentVisibility::Public;
    }

    private function isDeleted(?Comment $comment): bool
    {
        return $comment?->trashed() === true;
    }

    private function isAnonymized(?Comment $comment): bool
    {
        return $comment?->getAttribute('anonymized_at') !== null;
    }

    /**
     * Scope a query to public approved comments.
     *
     * @param  Builder<Comment>  $query
     */
    private function scopePublic(Builder $query): void
    {
        $query
            ->where(
                'status_hash',
                CommentIdentity::value('comment-status', CommentStatus::Approved),
            )
            ->where(
                'visibility_hash',
                CommentIdentity::value('comment-visibility', CommentVisibility::Public),
            );
    }
}
