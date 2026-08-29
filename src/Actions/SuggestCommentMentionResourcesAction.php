<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentMentionResourceData;
use Nvl\Comments\Data\CommentMentionSuggestionData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentMentionResourceRegistry;
use Nvl\Comments\ValueObjects\CommentMentionContext;

/**
 * Suggests one authorized registered resource type for a rich comment editor.
 */
final readonly class SuggestCommentMentionResourcesAction
{
    /**
     * Create the mention suggestion action.
     */
    public function __construct(
        private CommentAccessService $access,
        private CommentMentionResourceRegistry $resources,
    ) {}

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
    ): Collection {
        $this->access->authorize(
            CommentAbility::List,
            $actor,
            target: $target,
            audience: $audience,
            asNotFound: $audience !== CommentAudience::Management,
        );

        if (config('comments.mentions.enabled', false) !== true) {
            throw new InvalidCommentMutationException('Rich comment mentions are not enabled.');
        }

        return $this->resources
            ->suggest(
                $resource,
                new CommentMentionContext($target, $actor, $audience),
                $query,
                $limit,
            )
            ->map(static fn (CommentMentionResourceData $resolved): CommentMentionSuggestionData => CommentMentionSuggestionData::fromResource($resolved))
            ->values();
    }
}
