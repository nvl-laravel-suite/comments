<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\UpdateCommentData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Exceptions\StaleCommentException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentContentGuard;
use Nvl\Comments\Services\CommentMetadataIndexWriter;
use Nvl\Comments\Services\CommentMutationLock;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Support\CommentsConfiguration;
use Spatie\LaravelData\Optional;

/**
 * Replaces editable comment content while retaining immutable history.
 */
final readonly class UpdateCommentAction
{
    public function __construct(
        private CommentAccessService $access,
        private CommentContentGuard $guard,
        private CommentMutationLock $mutationLock,
        private CommentMetadataIndexWriter $metadataIndex,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
    ) {}

    /**
     * Replace editable content while preserving the previous revision.
     */
    public function execute(
        Comment|string $comment,
        UpdateCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment {
        $commentId = $comment instanceof Comment ? $comment->id : $comment;

        return $this->mutationLock->execute(
            $commentId,
            fn (): Comment => DB::connection((new Comment)->getConnectionName())
                ->transaction(function () use ($actor, $audience, $commentId, $data): Comment {
                    $comment = $this->reads->resolveById(
                        $commentId,
                        $actor,
                        $audience,
                        CommentAbility::Update,
                        withTrashed: false,
                        lockForUpdate: true,
                    );
                    $target = $this->targets->locate($comment);
                    $this->access->authorize(
                        CommentAbility::Update,
                        $actor,
                        $comment,
                        $target,
                        $audience,
                        asNotFound: $audience !== CommentAudience::Management,
                    );

                    if ($comment->revision !== $data->expectedRevision) {
                        throw StaleCommentException::forComment($comment->id);
                    }

                    $normalizedMetadata = $this->guard->update($data, $comment->metadata);

                    $format = $data->format instanceof Optional
                        ? $comment->format
                        : $data->format;
                    $locale = $data->locale instanceof Optional
                        ? $comment->locale
                        : $data->locale;
                    $tags = $data->tags instanceof Optional
                        ? $comment->tags
                        : $data->tags;
                    $metadata = $data->metadata instanceof Optional
                        ? $comment->metadata
                        : $normalizedMetadata;

                    if ($comment->body === $data->body
                        && $comment->format === $format
                        && $comment->locale === $locale
                        && $comment->tags === $tags
                        && $comment->metadata === $metadata) {
                        return $comment;
                    }

                    $revision = $comment->revisions()->create([
                        'revision' => $comment->revision,
                        'body' => $comment->body,
                        'format' => $comment->format,
                        'locale' => $comment->locale,
                        'tags' => $comment->tags,
                        'metadata' => $comment->metadata,
                        'edited_by_type' => $actor->type,
                        'edited_by' => $actor->id,
                    ]);

                    if (! $revision->exists) {
                        throw new InvalidCommentMutationException(
                            'The previous comment revision could not be saved.',
                        );
                    }

                    $configuredStatus = config('comments.moderation.edited_status', 'pending');
                    $status = is_string($configuredStatus)
                        ? CommentStatus::tryFrom($configuredStatus)
                        : null;
                    $attributes = [
                        'body' => $data->body,
                        'format' => $format,
                        'locale' => $locale,
                        'tags' => $tags,
                        'metadata' => $metadata,
                        'status' => $status ?? $comment->status,
                        'revision' => $comment->revision + 1,
                        'edited_at' => now(),
                    ];

                    if (! $comment->fill($attributes)->save()) {
                        throw new InvalidCommentMutationException(
                            'The comment update could not be saved.',
                        );
                    }

                    $this->metadataIndex->synchronize($comment, $metadata);

                    CommentChanged::dispatch(
                        $comment->id,
                        $comment->commentable_type,
                        $comment->commentable_id,
                        CommentChangeOperation::Updated,
                        $comment->revision,
                        $actor,
                    );

                    return $comment->refresh();
                }, attempts: CommentsConfiguration::positiveInteger(
                    'comments.transactions.attempts',
                    3,
                )),
        );
    }
}
