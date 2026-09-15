<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\DeleteCommentData;
use Nvl\Comments\Data\Queries\CommentSelectorData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentDeletionWriter;
use Nvl\Comments\Services\CommentMetadataIndexWriter;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentsConfiguration;

/**
 * Deletes one deterministic latest target match without exposing persistence models.
 */
final readonly class DeleteLatestTargetCommentAction
{
    /**
     * Create the package-owned latest-match deletion action.
     */
    public function __construct(
        private CommentDeletionWriter $writer,
        private CommentMetadataIndexWriter $metadataIndex,
        private CommentQueryScope $queryScope,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
    ) {}

    /**
     * Delete the latest authorized active match and return false when none exists.
     */
    public function execute(
        Model $target,
        CommentSelectorData $selector,
        CommentActorData $actor,
        CommentAudience $audience,
    ): bool {
        return DB::connection((new Comment)->getConnectionName())
            ->transaction(function () use ($actor, $audience, $selector, $target): bool {
                $target = $this->targets->reload($target);
                $query = $this->reads->query(
                    $target,
                    $actor,
                    $audience,
                    withTrashed: false,
                );
                $this->queryScope->scopeComments(
                    $query,
                    $actor,
                    $target,
                    $audience,
                    CommentAbility::Delete,
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

                if (! $comment instanceof Comment) {
                    return false;
                }

                return $this->writer->delete(
                    $comment,
                    new DeleteCommentData($comment->revision),
                    $actor,
                    $audience,
                );
            }, attempts: CommentsConfiguration::positiveInteger(
                'comments.transactions.attempts',
                3,
            ));
    }
}
