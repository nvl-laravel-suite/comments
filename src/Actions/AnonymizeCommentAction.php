<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\AnonymizeCommentData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Events\CommentMentionsChanged;
use Nvl\Comments\Exceptions\CommentMutationBusyException;
use Nvl\Comments\Exceptions\InvalidCommentLifecycleException;
use Nvl\Comments\Exceptions\StaleCommentException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentLifecycleGuard;
use Nvl\Comments\Services\CommentMentionWriter;
use Nvl\Comments\Services\CommentMetadataIndexWriter;
use Nvl\Comments\Services\CommentMutationLock;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Media\Contracts\DetachMediaContract;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Services\MediaMutationLock;

/**
 * Irreversibly anonymizes one comment while retaining thread and audit structure.
 */
final readonly class AnonymizeCommentAction
{
    /**
     * Create the terminal comment anonymization action.
     */
    public function __construct(
        private CommentAccessService $access,
        private DetachMediaContract $detachMedia,
        private CommentLifecycleGuard $guard,
        private MediaMutationLock $mediaLock,
        private CommentMutationLock $mutationLock,
        private CommentMetadataIndexWriter $metadataIndex,
        private CommentMentionWriter $mentions,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
    ) {}

    /**
     * Clear identifying content and associations exactly once.
     */
    public function execute(
        Comment|string $comment,
        AnonymizeCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment {
        $commentId = $comment instanceof Comment ? $comment->id : $comment;
        $this->guard->anonymization($data);
        $resolved = $this->reads->resolveById(
            $commentId,
            $actor,
            $audience,
            CommentAbility::Anonymize,
        );
        $target = $this->targets->locate($resolved);
        $this->access->authorize(
            CommentAbility::Anonymize,
            $actor,
            $resolved,
            $target,
            $audience,
            asNotFound: $audience !== CommentAudience::Management,
        );
        $attachmentStateRequired = $this->attachmentStateRequired($resolved);
        $commentConnection = DB::connection((new Comment)->getConnectionName());
        $lockIds = array_values(array_filter([$resolved->id, $resolved->parent_id]));

        return $this->mutationLock->executeMany(
            $lockIds,
            function () use (
                $actor,
                $audience,
                $commentConnection,
                $commentId,
                $data,
                $attachmentStateRequired,
                $lockIds,
            ): Comment {
                $authorizationComment = $this->reads->resolveById(
                    $commentId,
                    $actor,
                    $audience,
                    CommentAbility::Anonymize,
                );
                $target = $this->targets->locate($authorizationComment);
                $this->access->authorize(
                    CommentAbility::Anonymize,
                    $actor,
                    $authorizationComment,
                    $target,
                    $audience,
                    asNotFound: $audience !== CommentAudience::Management,
                );

                if ($authorizationComment->anonymized_at !== null) {
                    return $authorizationComment->refresh();
                }

                if ($authorizationComment->revision !== $data->expectedRevision) {
                    throw StaleCommentException::forComment($authorizationComment->id);
                }

                /** @var list<string> $mediaIds */
                $mediaIds = $attachmentStateRequired
                    ? $authorizationComment
                        ->mediaAssociations()
                        ->where('collection', 'attachments')
                        ->orderBy('media_id')
                        ->pluck('media_id')
                        ->filter(static fn (mixed $mediaId): bool => is_string($mediaId))
                        ->unique()
                        ->values()
                        ->all()
                    : [];

                return $this->mediaLock->executeMany(
                    $mediaIds,
                    fn (): Comment => $commentConnection->transaction(function () use (
                        $actor,
                        $audience,
                        $commentId,
                        $data,
                        $attachmentStateRequired,
                        $lockIds,
                        $mediaIds,
                    ): Comment {
                        sort($lockIds, SORT_STRING);
                        $lockedComments = Comment::query()
                            ->withTrashed()
                            ->whereKey($lockIds)
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get()
                            ->keyBy('id');
                        $comment = $lockedComments->get($commentId);

                        if (! $comment instanceof Comment) {
                            throw (new ModelNotFoundException)->setModel(
                                Comment::class,
                                [$commentId],
                            );
                        }

                        $this->reads->resolveById(
                            $commentId,
                            $actor,
                            $audience,
                            CommentAbility::Anonymize,
                        );

                        if ($comment->anonymized_at !== null) {
                            return $comment->refresh();
                        }

                        if ($comment->revision !== $data->expectedRevision) {
                            throw StaleCommentException::forComment($comment->id);
                        }

                        /** @var list<string> $lockedMediaIds */
                        $lockedMediaIds = $attachmentStateRequired
                            ? $comment
                                ->mediaAssociations()
                                ->where('collection', 'attachments')
                                ->orderBy('media_id')
                                ->lockForUpdate()
                                ->pluck('media_id')
                                ->filter(static fn (mixed $mediaId): bool => is_string($mediaId))
                                ->unique()
                                ->values()
                                ->all()
                            : [];

                        if ($lockedMediaIds !== $mediaIds) {
                            throw new CommentMutationBusyException(
                                'Comment attachments changed during anonymization; retry the request.',
                            );
                        }

                        foreach ($mediaIds as $mediaId) {
                            if ($this->detachMedia->execute(
                                $mediaId,
                                $comment,
                                'attachments',
                            ) === 0) {
                                throw new CommentMutationBusyException(
                                    'Comment attachments changed during anonymization; retry the request.',
                                );
                            }
                        }

                        $wasActive = ! $comment->trashed();
                        $commentActorType = $comment->actor_type;
                        $commentActorId = $comment->actor_id;

                        if ($commentActorType !== null && $commentActorId !== null) {
                            $actorIdentityHash = CommentIdentity::pair(
                                $commentActorType,
                                $commentActorId,
                            );
                            $comment->reactions()
                                ->where('actor_identity_hash', $actorIdentityHash)
                                ->delete();
                            $comment->reports()
                                ->where('reporter_identity_hash', $actorIdentityHash)
                                ->delete();
                        }

                        $mentionRemovals = $this->mentions->removals($comment);
                        $comment->revisions()->delete();
                        $this->metadataIndex->delete($comment);
                        $this->mentions->delete($comment);
                        $comment->reports()->update([
                            'details' => null,
                            'resolution' => null,
                        ]);
                        $reactionCount = $comment->reactions()->count();
                        $reportCount = $comment->reports()->count();
                        $openReportCount = $comment->reports()
                            ->where(
                                'status_hash',
                                CommentIdentity::value(
                                    'report-status',
                                    CommentReportStatus::Open,
                                ),
                            )
                            ->count();
                        $attributes = [
                            'actor_type' => null,
                            'actor_id' => null,
                            'body' => '',
                            'format' => CommentFormat::Plain,
                            'locale' => null,
                            'tags' => null,
                            'metadata' => null,
                            'document' => null,
                            'revision' => $comment->revision + 1,
                            'reaction_count' => $reactionCount,
                            'report_count' => $reportCount,
                            'open_report_count' => $openReportCount,
                            'moderated_by_type' => null,
                            'moderated_by' => null,
                            'moderation_reason' => null,
                            'moderated_at' => null,
                            'anonymized_at' => now(),
                            'anonymized_by_type' => $actor->type,
                            'anonymized_by' => $actor->id,
                            'anonymization_reason' => $data->reason,
                        ];

                        if ($wasActive) {
                            $attributes['deleted_by_type'] = $actor->type;
                            $attributes['deleted_by'] = $actor->id;
                        }

                        if (! $comment->forceFill($attributes)->save()) {
                            throw new InvalidCommentLifecycleException(
                                'The comment could not be anonymized.',
                            );
                        }

                        if ($wasActive && ! $comment->delete()) {
                            throw new InvalidCommentLifecycleException(
                                'The comment could not be deleted during anonymization.',
                            );
                        }

                        $parent = $comment->parent_id === null
                            ? null
                            : $lockedComments->get($comment->parent_id);

                        if ($wasActive
                            && $parent instanceof Comment
                            && $parent->reply_count > 0
                            && $parent->decrement('reply_count') !== 1) {
                            throw new InvalidCommentLifecycleException(
                                'The parent comment reply counter could not be updated.',
                            );
                        }

                        $comment->refresh();

                        if ($mentionRemovals !== []) {
                            CommentMentionsChanged::dispatch(
                                $comment->id,
                                $comment->commentable_type,
                                $comment->commentable_id,
                                $comment->revision,
                                [],
                                $mentionRemovals,
                            );
                        }
                        CommentChanged::dispatch(
                            $comment->id,
                            $comment->commentable_type,
                            $comment->commentable_id,
                            CommentChangeOperation::Anonymized,
                            $comment->revision,
                            $actor,
                        );

                        return $comment;
                    }, attempts: CommentsConfiguration::positiveInteger(
                        'comments.transactions.attempts',
                        3,
                    )),
                );
            },
        );
    }

    /**
     * Require Media only when attachments are enabled or historical state needs scrubbing.
     */
    private function attachmentStateRequired(Comment $comment): bool
    {
        $attachmentsEnabled = config('comments.attachments.enabled', true) === true;
        $association = new MediaAssociation;
        $associationSchema = Schema::connection($association->getConnectionName());

        if (! $associationSchema->hasTable($association->getTable())) {
            if (! $attachmentsEnabled) {
                return false;
            }

            throw new InvalidArgumentException(
                'Comment anonymization requires the Media association table when attachments are enabled.',
            );
        }

        $hasHistoricalState = MediaAssociation::query()
            ->where('associable_type', $comment->getMorphClass())
            ->where('associable_id', $comment->id)
            ->where('collection', 'attachments')
            ->exists();

        if (! $attachmentsEnabled && ! $hasHistoricalState) {
            return false;
        }

        $media = new Media;
        $commentConnection = DB::connection($comment->getConnectionName())->getName();
        $associationConnection = DB::connection($association->getConnectionName())->getName();
        $mediaConnection = DB::connection($media->getConnectionName())->getName();
        $mediaTableExists = Schema::connection($media->getConnectionName())
            ->hasTable($media->getTable());

        if ($commentConnection !== $associationConnection
            || $commentConnection !== $mediaConnection
            || ! $mediaTableExists) {
            throw new InvalidArgumentException(
                'Comment anonymization requires Comments and complete Media state on one database connection when attachment state exists.',
            );
        }

        return true;
    }
}
