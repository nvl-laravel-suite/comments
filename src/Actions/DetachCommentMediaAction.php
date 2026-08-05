<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentMutationLock;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Media\Contracts\DetachMediaContract;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Services\MediaMutationLock;

/**
 * Detaches one authorized comment association without deleting its Media record.
 */
final readonly class DetachCommentMediaAction
{
    public function __construct(
        private CommentAccessService $access,
        private CommentMutationLock $commentLock,
        private DetachMediaContract $detachMedia,
        private MediaAuthorization $mediaAuthorization,
        private MediaMutationLock $mediaLock,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
    ) {}

    /**
     * Remove one attachment association and treat an absent association as an idempotent no-op.
     */
    public function execute(
        Comment|string $comment,
        string $associationId,
        CommentActorData $actor,
        CommentAudience $audience,
    ): bool {
        $commentId = $comment instanceof Comment ? $comment->id : $comment;

        return $this->commentLock->execute(
            $commentId,
            function () use ($actor, $associationId, $audience, $commentId): bool {
                $comment = $this->reads->resolveById(
                    $commentId,
                    $actor,
                    $audience,
                    CommentAbility::Detach,
                    withTrashed: false,
                );
                $this->access->authorize(
                    CommentAbility::Detach,
                    $actor,
                    $comment,
                    $this->targets->locate($comment),
                    $audience,
                    asNotFound: $audience !== CommentAudience::Management,
                );
                $mediaId = MediaAssociation::query()
                    ->whereKey($associationId)
                    ->value('media_id');

                if (! is_string($mediaId) || $mediaId === '') {
                    return false;
                }

                return $this->mediaLock->execute(
                    $mediaId,
                    function () use (
                        $actor,
                        $associationId,
                        $audience,
                        $commentId,
                        $mediaId,
                    ): bool {
                        return DB::connection((new Comment)->getConnectionName())
                            ->transaction(function () use (
                                $actor,
                                $associationId,
                                $audience,
                                $commentId,
                                $mediaId,
                            ): bool {
                                $comment = $this->reads->resolveById(
                                    $commentId,
                                    $actor,
                                    $audience,
                                    CommentAbility::Detach,
                                    withTrashed: false,
                                    lockForUpdate: true,
                                );
                                $media = Media::query()
                                    ->withTrashed()
                                    ->lockForUpdate()
                                    ->findOrFail($mediaId);
                                $association = MediaAssociation::query()
                                    ->whereKey($associationId)
                                    ->where('media_id', $media->id)
                                    ->where('associable_type', $comment->getMorphClass())
                                    ->where('associable_id', $comment->id)
                                    ->where('collection', 'attachments')
                                    ->where('is_active', true)
                                    ->lockForUpdate()
                                    ->first();

                                if (! $association instanceof MediaAssociation) {
                                    return false;
                                }

                                $this->assertSharedConnection($comment, $media, $association);
                                $target = $this->targets->locate($comment);
                                $this->access->authorize(
                                    CommentAbility::Detach,
                                    $actor,
                                    $comment,
                                    $target,
                                    $audience,
                                    asNotFound: $audience !== CommentAudience::Management,
                                );
                                $mediaActor = new MediaActorData(
                                    $actor->type,
                                    $actor->id,
                                    $actor->system,
                                );

                                if (! $this->mediaAuthorization->allows(
                                    $mediaActor,
                                    MediaAbility::Mutate,
                                    $media,
                                    $comment,
                                )) {
                                    throw new AuthorizationException(
                                        'The actor may not detach this media from the comment.',
                                    );
                                }

                                return $this->detachMedia->execute(
                                    $media,
                                    $comment,
                                    'attachments',
                                ) > 0;
                            }, attempts: CommentsConfiguration::positiveInteger(
                                'comments.transactions.attempts',
                                3,
                            ));
                    },
                );
            },
        );
    }

    /**
     * Require Comments, Media, and associations to participate in one transaction.
     */
    private function assertSharedConnection(
        Comment $comment,
        Media $media,
        MediaAssociation $association,
    ): void {
        $commentConnection = DB::connection($comment->getConnectionName())->getName();
        $mediaConnection = DB::connection($media->getConnectionName())->getName();
        $associationConnection = DB::connection($association->getConnectionName())->getName();

        if ($commentConnection !== $mediaConnection
            || $commentConnection !== $associationConnection) {
            throw new InvalidArgumentException(
                'Comment attachments require Comments and Media to use the same database connection.',
            );
        }
    }
}
