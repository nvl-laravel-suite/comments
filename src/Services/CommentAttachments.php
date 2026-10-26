<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Integrations\MediaCommentRelations;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Ownership\CommentsOwnership;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Media\Services\MediaAttachments;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Support\MediaActors;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Integrations\OptionalIntegration;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/**
 * Comment-authorized association operations over the optional native Media service.
 *
 * @api
 */
final readonly class CommentAttachments
{
    public function __construct(
        private CommentAccessService $access,
        private CommentMutationLock $locks,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
        private OwnershipBoundary $boundary,
        private OptionalIntegration $integrations,
    ) {}

    public function attach(
        Comment|string $comment,
        Media|string $media,
        Authenticatable|SystemActor $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): MediaAssociation {
        $this->requireMedia();
        $actor = MediaActors::assert($actor);
        CommentsOwnership::assertAttachmentSupport();
        $commentId = $comment instanceof Comment ? $comment->id : $comment;
        $mediaId = $media instanceof Media ? $media->id : $media;
        $identity = CommentActor::from($actor);
        $connection = DB::connection((CommentModelRegistry::newInstance())->getConnectionName());
        if ($connection->getName() !== DB::connection((MediaModelRegistry::newInstance())->getConnectionName())->getName()) {
            throw new InvalidArgumentException('Comment attachments require Comments and Media on one connection.');
        }

        return $this->locks->execute($commentId, fn (): MediaAssociation => $connection->transaction(function () use ($actor, $audience, $commentId, $identity, $mediaId): MediaAssociation {
            $comment = $this->reads->resolveById($commentId, $identity, $audience, CommentAbility::Attach, withTrashed: false, lockForUpdate: true);
            $this->access->authorize(CommentAbility::Attach, $identity, $comment, $this->targets->locate($comment), $audience, asNotFound: $audience !== CommentAudience::Management);
            $media = $this->boundary->query(MediaModelRegistry::query(), 'media.assets')->lockForUpdate()->findOrFail($mediaId);
            $authorization = app(MediaAuthorization::class);
            foreach ([MediaAbility::Associate, MediaAbility::View, MediaAbility::Download] as $ability) {
                if (! $authorization->allows($actor, $ability, $media, $comment)) {
                    throw new AuthorizationException('The actor may not attach or receive this media.');
                }
            }
            if ($media->is_public && config('nvl-comments.attachments.allow_public_media', false) !== true) {
                throw new InvalidCommentMutationException('Public media is not enabled for comment attachments.');
            }
            $slot = MediaCommentRelations::slot();
            if ($slot->acceptedMimeTypes !== [] && ! in_array($media->mime_type, $slot->acceptedMimeTypes, true)) {
                throw new InvalidCommentMutationException('The media type is not allowed for comment attachments.');
            }
            if ($slot->maxFileSize !== null && $media->size > $slot->maxFileSize) {
                throw new InvalidCommentMutationException('The media file exceeds the comment attachment size limit.');
            }
            $existing = $comment->attachmentAssociations()->where('media_id', $media->id)->exists();
            $maximum = CommentsConfiguration::positiveInteger('nvl-comments.attachments.maximum_per_comment', 5);
            if (! $existing && $comment->attachmentAssociations()->count() >= $maximum) {
                throw new InvalidCommentMutationException("A comment may contain at most {$maximum} attachments.");
            }

            return app(MediaAttachments::class)->attach(
                $actor,
                $media,
                $comment,
                'attachments',
                metadata: ['slot' => 'attachments'],
                slot: $slot,
            );
        }, attempts: CommentsConfiguration::positiveInteger('nvl-comments.transactions.attempts', 3)));
    }

    public function detach(
        Comment|string $comment,
        string $associationId,
        Authenticatable|SystemActor $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): bool {
        $this->requireMedia();
        $actor = MediaActors::assert($actor);
        CommentsOwnership::assertAttachmentSupport();
        $commentId = $comment instanceof Comment ? $comment->id : $comment;
        $identity = CommentActor::from($actor);

        return $this->locks->execute($commentId, function () use ($actor, $associationId, $audience, $commentId, $identity): bool {
            $comment = $this->reads->resolveById($commentId, $identity, $audience, CommentAbility::Detach, withTrashed: false);
            $this->access->authorize(CommentAbility::Detach, $identity, $comment, $this->targets->locate($comment), $audience, asNotFound: $audience !== CommentAudience::Management);
            $association = $this->boundary->query(MediaAssociation::query(), 'media.associations')
                ->whereKey($associationId)
                ->where('associable_type', $comment->getMorphClass())
                ->where('associable_id', $comment->id)
                ->where('collection', 'attachments')
                ->where('is_active', true)
                ->first();
            if (! $association instanceof MediaAssociation) {
                return false;
            }

            return app(MediaAttachments::class)->detach($actor, $association->media_id, $comment, 'attachments') > 0;
        });
    }

    private function requireMedia(): void
    {
        $this->integrations->enabled('comments.attachments.enabled', MediaServiceProvider::class, requested: true);
    }
}
