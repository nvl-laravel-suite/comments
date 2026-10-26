<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Support\Enums\PublicActor;
use Nvl\Support\Enums\SystemActor;

/** Explicitly admits background moderation for native operation tests. */
final class NativeModerationPolicy implements CommentAuthorization, CommentQueryScope
{
    public function allows(
        CommentAbility $ability,
        Authenticatable|SystemActor|PublicActor $actor,
        ?Comment $comment = null,
        ?Model $target = null,
        CommentAudience $audience = CommentAudience::Public,
        array $context = [],
    ): bool {
        return $actor === SystemActor::Background
            && in_array($ability, [CommentAbility::Moderate, CommentAbility::Anonymize], true);
    }

    public function scopeComments(
        Builder $query,
        Authenticatable|SystemActor|PublicActor $actor,
        Model $target,
        CommentAudience $audience,
        CommentAbility $ability,
    ): void {
        if ($actor !== SystemActor::Background
            || ! in_array($ability, [CommentAbility::Moderate, CommentAbility::Anonymize], true)) {
            $query->whereRaw('1 = 0');
        }
    }
}
