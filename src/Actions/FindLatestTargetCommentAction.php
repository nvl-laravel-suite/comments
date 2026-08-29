<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentManagementData;
use Nvl\Comments\Data\MemberCommentData;
use Nvl\Comments\Data\PublicCommentData;
use Nvl\Comments\Data\Queries\CommentSelectorData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Services\CommentMetadataIndexWriter;
use Nvl\Comments\Services\CommentProjectionFactory;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Support\CommentIdentity;

/**
 * Resolves one newest authorized target comment through bounded selectors.
 */
final readonly class FindLatestTargetCommentAction
{
    /**
     * Create the latest-comment action.
     */
    public function __construct(
        private CommentReadService $reads,
        private CommentProjectionFactory $projections,
        private CommentMetadataIndexWriter $metadataIndex,
    ) {}

    /**
     * Return one audience-safe projection, or null when no visible row matches.
     */
    public function execute(
        Model $target,
        CommentActorData $actor,
        CommentSelectorData $selector,
        CommentAudience $audience = CommentAudience::Member,
    ): PublicCommentData|MemberCommentData|CommentManagementData|null {
        $query = $this->reads->query(
            $target,
            $actor,
            $audience,
            withTrashed: false,
        );

        foreach ($selector->tags as $tag) {
            $query->whereJsonContains('tags', $tag);
        }

        $this->metadataIndex->apply($query, $selector->metadataEquals);

        if ($selector->status !== null) {
            $query->where(
                'status_hash',
                CommentIdentity::value('comment-status', $selector->status),
            );
        }

        $comment = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($comment === null) {
            return null;
        }

        return match ($audience) {
            CommentAudience::Public => $this->projections->publicComment($comment, $target),
            CommentAudience::Member => $this->projections->memberComment($comment, $target, $actor),
            CommentAudience::Management => $this->projections->managementComment(
                $comment,
                $target,
                $actor,
            ),
        };
    }
}
