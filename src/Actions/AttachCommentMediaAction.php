<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentMutationLock;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Media\Contracts\AttachMediaContract;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Slots\MediaSlot;

/**
 * Attaches available media only after comment and media ownership authorization.
 */
final readonly class AttachCommentMediaAction
{
    public function __construct(
        private CommentAccessService $access,
        private CommentMutationLock $commentLock,
        private MediaAuthorization $mediaAuthorization,
        private AttachMediaContract $attachMedia,
        private MediaMutationLock $mediaLock,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
    ) {}

    /**
     * Attach authorized media when Comments and Media share one database connection.
     */
    public function execute(
        Comment|string $comment,
        Media|string $media,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): MediaAssociation {
        $commentId = $comment instanceof Comment ? $comment->id : $comment;
        $mediaId = $media instanceof Media ? $media->id : $media;

        if (config('comments.attachments.enabled', true) !== true) {
            throw new InvalidCommentMutationException(
                'Comment attachments are disabled.',
            );
        }

        $commentConnection = DB::connection((new Comment)->getConnectionName());
        $mediaConnection = DB::connection((new Media)->getConnectionName());

        if ($commentConnection->getName() !== $mediaConnection->getName()) {
            throw new InvalidArgumentException(
                'Comment attachments require Comments and Media to use the same database connection.',
            );
        }

        return $this->commentLock->execute(
            $commentId,
            fn (): MediaAssociation => $this->mediaLock->execute(
                $mediaId,
                fn (): MediaAssociation => $commentConnection->transaction(function () use (
                    $actor,
                    $audience,
                    $commentId,
                    $mediaId,
                ): MediaAssociation {
                    $comment = $this->reads->resolveById(
                        $commentId,
                        $actor,
                        $audience,
                        CommentAbility::Attach,
                        withTrashed: false,
                        lockForUpdate: true,
                    );
                    $media = Media::query()->lockForUpdate()->findOrFail($mediaId);
                    $target = $this->targets->locate($comment);
                    $this->access->authorize(
                        CommentAbility::Attach,
                        $actor,
                        $comment,
                        $target,
                        $audience,
                        asNotFound: $audience !== CommentAudience::Management,
                    );
                    $mediaActor = new MediaActorData($actor->type, $actor->id, $actor->system);

                    if (! $this->mediaAuthorization->allows(
                        $mediaActor,
                        MediaAbility::Associate,
                        $media,
                        $comment,
                    )) {
                        throw new AuthorizationException(
                            'The actor may not attach this media to the comment.',
                        );
                    }

                    if (! $this->mediaAuthorization->allows(
                        $mediaActor,
                        MediaAbility::View,
                        $media,
                        $comment,
                    ) || ! $this->mediaAuthorization->allows(
                        $mediaActor,
                        MediaAbility::Download,
                        $media,
                        $comment,
                    )) {
                        throw new AuthorizationException(
                            'The actor may not receive this comment attachment.',
                        );
                    }

                    if ($media->is_public
                        && config('comments.attachments.allow_public_media', false) !== true) {
                        throw new InvalidCommentMutationException(
                            'Public media is not enabled for comment attachments.',
                        );
                    }

                    $slot = $comment->getMediaSlot('attachments');

                    if ($slot === null) {
                        throw new InvalidCommentMutationException(
                            'The comment attachment slot is not configured.',
                        );
                    }

                    if ($slot->acceptedMimeTypes !== []
                        && ! in_array($media->mime_type, $slot->acceptedMimeTypes, true)) {
                        throw new InvalidCommentMutationException(
                            "Media type [{$media->mime_type}] is not allowed for comment attachments.",
                        );
                    }

                    if ($slot->maxFileSize !== null && $media->size > $slot->maxFileSize) {
                        throw new InvalidCommentMutationException(
                            'The media file exceeds the comment attachment size limit.',
                        );
                    }

                    if (! $media->is_public
                        && $slot->sharingMode === MediaSlot::SHARING_EXCLUSIVE
                        && $this->hasAnotherAssociation($media, $comment)) {
                        throw new InvalidCommentMutationException(
                            'Private comment attachments may not be shared with another owner.',
                        );
                    }

                    $maximum = CommentsConfiguration::positiveInteger(
                        'comments.attachments.maximum_per_comment',
                        5,
                    );
                    $existing = $comment->attachmentAssociations()
                        ->where('media_id', $media->id)
                        ->exists();

                    if (! $existing
                        && $comment->attachmentAssociations()->count() >= $maximum) {
                        throw new InvalidCommentMutationException(
                            "A comment may contain at most {$maximum} attachments.",
                        );
                    }

                    return $this->attachMedia->execute(
                        $media,
                        $comment,
                        'attachments',
                        metadata: ['slot' => 'attachments'],
                    );
                }, attempts: CommentsConfiguration::positiveInteger(
                    'comments.transactions.attempts',
                    3,
                )),
            ),
        );
    }

    /**
     * Determine whether an exclusive media record already belongs elsewhere.
     */
    private function hasAnotherAssociation(Media $media, Comment $comment): bool
    {
        return $media->associations()
            ->where(function ($query) use ($comment): void {
                $query
                    ->where('associable_type', '!=', $comment->getMorphClass())
                    ->orWhere('associable_id', '!=', $comment->id)
                    ->orWhere('collection', '!=', 'attachments');
            })
            ->exists();
    }
}
