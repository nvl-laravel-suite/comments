<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Queries\CommentSelectorData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Exceptions\InvalidCommentLifecycleException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentMetadataIndexWriter;
use Nvl\Comments\Services\CommentReadService;
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
        private CommentAccessService $access,
        private CommentMetadataIndexWriter $metadataIndex,
        private CommentReadService $reads,
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
                    ->lockForUpdate()
                    ->first();

                if (! $comment instanceof Comment) {
                    return false;
                }

                $this->access->authorize(
                    CommentAbility::Delete,
                    $actor,
                    $comment,
                    $target,
                    $audience,
                    asNotFound: $audience !== CommentAudience::Management,
                );

                if ($comment->trashed() || $comment->anonymized_at !== null) {
                    throw new InvalidCommentLifecycleException(
                        'Only an active comment may be deleted.',
                    );
                }

                $parent = $comment->parent_id === null
                    ? null
                    : Comment::query()
                        ->withTrashed()
                        ->whereKey($comment->parent_id)
                        ->lockForUpdate()
                        ->first();

                if (! $comment->forceFill([
                    'revision' => $comment->revision + 1,
                    'deleted_by_type' => $actor->type,
                    'deleted_by' => $actor->id,
                ])->save()) {
                    throw new InvalidCommentLifecycleException(
                        'The comment deletion audit could not be saved.',
                    );
                }

                if (! $comment->delete()) {
                    throw new InvalidCommentLifecycleException(
                        'The comment could not be deleted.',
                    );
                }

                $this->metadataIndex->delete($comment);

                if ($parent instanceof Comment
                    && $parent->reply_count > 0
                    && $parent->decrement('reply_count') !== 1) {
                    throw new InvalidCommentLifecycleException(
                        'The parent comment reply counter could not be updated.',
                    );
                }

                CommentChanged::dispatch(
                    $comment->id,
                    $comment->commentable_type,
                    $comment->commentable_id,
                    CommentChangeOperation::Deleted,
                    $comment->revision,
                    $actor,
                );

                return true;
            }, attempts: CommentsConfiguration::positiveInteger(
                'comments.transactions.attempts',
                3,
            ));
    }
}
