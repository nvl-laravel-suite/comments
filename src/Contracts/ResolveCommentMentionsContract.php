<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Support\Collection;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentMentionData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;

/**
 * Defines the supported resolve comment mentions workflow.
 *
 * @api
 */
interface ResolveCommentMentionsContract
{
    /**
     * Resolve one comment after its audience access boundary and return token order.
     *
     * @return Collection<int, CommentMentionData>
     */
    public function execute(
        Comment|string $comment,
        CommentActorData $actor,
        CommentAudience $audience,
    ): Collection;
}
