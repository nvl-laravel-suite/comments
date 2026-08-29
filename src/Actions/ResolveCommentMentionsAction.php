<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentMentionData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentMentionProjectionFactory;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\CommentTargetLocator;
use Spatie\LaravelData\Optional;

/**
 * Resolves one authorized comment's current viewer-safe mention projections.
 */
final readonly class ResolveCommentMentionsAction
{
    /**
     * Create the current mention resolution action.
     */
    public function __construct(
        private CommentMentionProjectionFactory $projections,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
    ) {}

    /**
     * Resolve one comment after its audience access boundary and return token order.
     *
     * @return Collection<int, CommentMentionData>
     */
    public function execute(
        Comment|string $comment,
        CommentActorData $actor,
        CommentAudience $audience,
    ): Collection {
        $comment = $this->reads->findById($comment, $actor, $audience);
        $projected = $this->projections->project(
            new EloquentCollection([$comment]),
            $this->targets->locate($comment),
            $actor,
            $audience,
        )[$comment->id]['mentions'];

        $mentions = [];

        if (! $projected instanceof Optional) {
            foreach ($projected as $mention) {
                $mentions[] = $mention;
            }
        }

        return collect($mentions);
    }
}
