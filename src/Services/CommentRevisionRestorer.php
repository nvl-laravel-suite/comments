<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Events\CommentMentionsChanged;
use Nvl\Comments\Exceptions\InvalidCommentLifecycleException;
use Nvl\Comments\Exceptions\StaleCommentException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/**
 * Restores historical comment content as a new optimistic revision.
 *
 * @internal
 */
final readonly class CommentRevisionRestorer
{
    /**
     * Create the historical revision restoration action.
     */
    public function __construct(
        private CommentAccessService $access,
        private CommentLifecycleGuard $guard,
        private CommentDocumentNormalizer $documents,
        private CommentMentionWriter $mentions,
        private CommentMutationLock $mutationLock,
        private CommentMetadataGuard $metadataGuard,
        private CommentMetadataIndexWriter $metadataIndex,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
        private OwnershipBoundary $boundary,
        private DomainEventDispatcher $domainEvents,
    ) {}

    /**
     * Apply one owned historical snapshot while preserving the current snapshot.
     */
    public function restore(
        Comment|string $comment,
        CommentRevision|string $revision,
        int $expectedRevision,
        CommentActor $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment {
        $commentId = $comment instanceof Comment ? $comment->id : $comment;
        $revisionId = $revision instanceof CommentRevision ? $revision->id : $revision;
        $this->guard->expectedRevision($expectedRevision);

        return $this->mutationLock->execute(
            $commentId,
            function () use (
                $actor,
                $audience,
                $commentId,
                $expectedRevision,
                $revisionId,
            ): Comment {
                return DB::connection((CommentModelRegistry::newInstance())->getConnectionName())
                    ->transaction(function () use (
                        $actor,
                        $audience,
                        $commentId,
                        $expectedRevision,
                        $revisionId,
                    ): Comment {
                        $comment = $this->reads->resolveById(
                            $commentId,
                            $actor,
                            $audience,
                            CommentAbility::RestoreRevision,
                            withTrashed: true,
                            lockForUpdate: true,
                        );
                        $target = $this->targets->locate($comment);
                        $this->access->authorize(
                            CommentAbility::RestoreRevision,
                            $actor,
                            $comment,
                            $target,
                            $audience,
                            asNotFound: $audience !== CommentAudience::Management,
                        );

                        if ($comment->trashed() || $comment->anonymized_at !== null) {
                            throw new InvalidCommentLifecycleException(
                                'Historical content may only be restored to an active, non-anonymized comment.',
                            );
                        }

                        if ($comment->revision !== $expectedRevision) {
                            throw StaleCommentException::forComment($comment->id);
                        }

                        if (! Str::isUuid($revisionId)) {
                            throw (new ModelNotFoundException)->setModel(CommentRevision::class, [$revisionId]);
                        }
                        $revision = $this->boundary->query(CommentRevision::query(), 'comments.revisions')
                            ->where('comment_id', $comment->id)
                            ->whereKey($revisionId)
                            ->lockForUpdate()
                            ->firstOrFail();

                        if ($revision->revision >= $comment->revision) {
                            throw new InvalidCommentLifecycleException(
                                'Only a historical comment revision may be restored.',
                            );
                        }

                        $currentMetadata = $this->metadataGuard->normalize(
                            $comment->metadata ?? [],
                        );
                        $currentDocument = $comment->format === CommentFormat::RichText
                            && is_array($comment->document)
                                ? $this->documents->normalizeStored($comment->document)
                                : null;
                        $restoredMetadata = $this->metadataGuard->normalize(
                            $revision->metadata ?? [],
                            $currentMetadata,
                        );

                        $currentRevision = CommentRecordWriter::create(CommentRevision::class, [
                            'comment_id' => $comment->id,
                            'revision' => $comment->revision,
                            'body' => $comment->body,
                            'format' => $comment->format,
                            'locale' => $comment->locale,
                            'tags' => $comment->tags,
                            'metadata' => $currentMetadata,
                            'document' => $comment->document,
                            'edited_by_type' => $actor->type,
                            'edited_by' => $actor->id,
                        ], restoring: true);

                        if (! $currentRevision->exists) {
                            throw new InvalidCommentLifecycleException(
                                'The current comment revision could not be preserved.',
                            );
                        }

                        $configuredStatus = config(
                            'nvl-comments.moderation.edited_status',
                            CommentStatus::Pending->value,
                        );
                        $status = is_string($configuredStatus)
                            ? CommentStatus::tryFrom($configuredStatus)
                            : null;
                        $restoredDocument = null;
                        $restoredBody = $revision->body;
                        $encodedSource = $revision->format !== CommentFormat::RichText && is_string($revision->document['codec'] ?? null)
                            ? $revision->document
                            : null;

                        if ($revision->format === CommentFormat::RichText) {
                            if (! is_array($revision->document)) {
                                throw new InvalidCommentLifecycleException(
                                    'The historical rich comment document is unavailable.',
                                );
                            }

                            $restoredDocument = $this->documents->normalizeStored(
                                $revision->document,
                            );
                            $restoredBody = $this->documents->body($restoredDocument);
                        }

                        $saved = $comment->forceFill([
                            'body' => $restoredBody,
                            'format' => $revision->format,
                            'locale' => $revision->locale,
                            'tags' => $revision->tags,
                            'metadata' => $restoredMetadata,
                            'document' => $restoredDocument === null
                                ? $encodedSource
                                : $this->documents->toArray($restoredDocument),
                            'status' => $status ?? $comment->status,
                            'revision' => $comment->revision + 1,
                            'edited_at' => now(),
                            'edited_by_type' => $actor->type,
                            'edited_by' => $actor->id,
                        ])->save();

                        if (! $saved) {
                            throw new InvalidCommentLifecycleException(
                                'The historical comment revision could not be restored.',
                            );
                        }

                        $this->metadataIndex->synchronize($comment, $restoredMetadata);
                        $this->mentions->synchronize($comment, $restoredDocument);
                        $mentionChanges = $this->mentions->changes(
                            $currentDocument,
                            $restoredDocument,
                        );

                        if ($mentionChanges['added'] !== []
                            || $mentionChanges['removed'] !== []) {
                            $this->domainEvents->dispatch(new CommentMentionsChanged(
                                $comment->id,
                                $comment->commentable_type,
                                $comment->commentable_id,
                                $comment->revision,
                                $mentionChanges['added'],
                                $mentionChanges['removed'],
                            ), $comment->getConnection());
                        }

                        $comment->refresh();
                        $this->domainEvents->dispatch(new CommentChanged(
                            $comment->id,
                            $comment->commentable_type,
                            $comment->commentable_id,
                            CommentChangeOperation::RevisionRestored,
                            $comment->revision,
                            $actor,
                        ), $comment->getConnection());

                        return $comment;
                    }, attempts: CommentsConfiguration::positiveInteger(
                        'nvl-comments.transactions.attempts',
                        3,
                    ));
            },
        );
    }
}
