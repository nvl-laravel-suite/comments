<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Comments\ValueObjects\CommentMentionContext;
use Nvl\Support\Enums\PublicActor;
use Nvl\Support\Enums\SystemActor;

/**
 * Viewer-safe registered resource lookups for rich comments.
 *
 * @api
 */
final readonly class CommentMentions
{
    public function __construct(
        private CommentAccessService $access,
        private CommentMentionProjectionFactory $projections,
        private CommentMentionResourceRegistry $resources,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
    ) {}

    /** @return list<array<string, mixed>> */
    public function resolve(
        Comment|string $comment,
        Authenticatable|SystemActor|PublicActor $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): array {
        $identity = CommentActor::from($actor);
        $comment = $this->reads->findById($comment, $identity, $audience);
        $projected = $this->projections->project(
            new Collection([$comment]),
            $this->targets->locate($comment),
            $identity,
            $audience,
        )[$comment->id]['mentions'];

        return $projected;
    }

    /** @return list<array{id: string, label: ?string, fields: array<string, scalar|null>, url: ?string}> */
    public function suggest(
        Model $target,
        Authenticatable|SystemActor|PublicActor $actor,
        string $resource,
        string $query,
        ?int $limit = null,
        CommentAudience $audience = CommentAudience::Public,
    ): array {
        $target = $this->targets->reload($target);
        $identity = CommentActor::from($actor);
        $this->access->authorize(
            CommentAbility::List,
            $identity,
            target: $target,
            audience: $audience,
            asNotFound: $audience !== CommentAudience::Management,
        );
        if (config('nvl-comments.mentions.enabled', false) !== true) {
            throw new InvalidCommentMutationException('Rich comment mentions are not enabled.');
        }

        $suggestions = $this->resources->suggest(
            $resource,
            new CommentMentionContext($target, $identity, $audience),
            $query,
            $limit ?? CommentsConfiguration::positiveInteger('nvl-comments.mentions.suggestion_limit', 10),
        )->map(static fn ($resolved): array => [
            'id' => $resolved['id'],
            'label' => $resolved['label'],
            'fields' => $resolved['fields'],
            'url' => $resolved['url'],
        ])->values()->all();

        return array_values($suggestions);
    }
}
