<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentMentionSuggestionData;
use Nvl\Comments\Enums\CommentAudience;

/**
 * Defines the supported suggest comment mention resources workflow.
 *
 * @api
 */
interface SuggestCommentMentionResourcesContract
{
    /**
     * Return one bounded authorized suggestion collection after target authorization.
     *
     * @return Collection<int, CommentMentionSuggestionData>
     */
    public function execute(
        Model $target,
        CommentActorData $actor,
        CommentAudience $audience,
        string $resource,
        string $query,
        int $limit = 10,
    ): Collection;
}
