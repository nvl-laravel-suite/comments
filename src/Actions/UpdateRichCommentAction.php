<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\UpdateRichCommentData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Events\CommentMentionsChanged;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Exceptions\StaleCommentException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentContentGuard;
use Nvl\Comments\Services\CommentDocumentNormalizer;
use Nvl\Comments\Services\CommentMentionWriter;
use Nvl\Comments\Services\CommentMetadataIndexWriter;
use Nvl\Comments\Services\CommentMutationLock;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Comments\ValueObjects\CommentMentionContext;
use Spatie\LaravelData\Optional;

/**
 * Replaces rich comment content and mention rows while retaining history.
 */
final readonly class UpdateRichCommentAction
{
    /**
     * Create the rich comment update action.
     */
    public function __construct(
        private CommentAccessService $access,
        private CommentContentGuard $guard,
        private CommentDocumentNormalizer $documents,
        private CommentMentionWriter $mentions,
        private CommentMetadataIndexWriter $metadataIndex,
        private CommentMutationLock $mutationLock,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
    ) {}

    /**
     * Replace authorized rich content with optimistic concurrency protection.
     */
    public function execute(
        Comment|string $comment,
        UpdateRichCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment {
        $commentId = $comment instanceof Comment ? $comment->id : $comment;
        $inputDocument = $this->documents->normalizeUnresolved($data->document);

        return $this->mutationLock->execute(
            $commentId,
            fn (): Comment => DB::connection((new Comment)->getConnectionName())
                ->transaction(function () use (
                    $actor,
                    $audience,
                    $commentId,
                    $data,
                    $inputDocument,
                ): Comment {
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

                    $previousDocument = is_array($comment->document)
                        ? $this->documents->normalizeStored($comment->document)
                        : null;

                    $document = $this->documents->normalizeInput(
                        $inputDocument,
                        new CommentMentionContext($target, $actor, $audience),
                    );
                    $body = $this->documents->body($document);
                    $normalizedMetadata = $this->guard->updateRich(
                        $data,
                        $body,
                        $comment->metadata,
                    );
                    $locale = $data->locale instanceof Optional
                        ? $comment->locale
                        : $data->locale;
                    $tags = $data->tags instanceof Optional
                        ? $comment->tags
                        : $data->tags;
                    $metadata = $data->metadata instanceof Optional
                        ? $comment->metadata
                        : $normalizedMetadata;
                    $documentArray = $this->documents->toArray($document);
                    $previousDocumentArray = $previousDocument === null
                        ? null
                        : $this->documents->toArray($previousDocument);

                    if ($comment->body === $body
                        && $comment->format === CommentFormat::RichText
                        && $previousDocumentArray === $documentArray
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
                        'document' => $comment->document,
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

                    if (! $comment->fill([
                        'body' => $body,
                        'format' => CommentFormat::RichText,
                        'locale' => $locale,
                        'tags' => $tags,
                        'metadata' => $metadata,
                        'document' => $documentArray,
                        'status' => $status ?? $comment->status,
                        'revision' => $comment->revision + 1,
                        'edited_at' => now(),
                    ])->save()) {
                        throw new InvalidCommentMutationException(
                            'The rich comment update could not be saved.',
                        );
                    }

                    $this->metadataIndex->synchronize($comment, $metadata);
                    $this->mentions->synchronize($comment, $document);
                    $mentionChanges = $this->mentions->changes($previousDocument, $document);

                    if ($mentionChanges['added'] !== [] || $mentionChanges['removed'] !== []) {
                        CommentMentionsChanged::dispatch(
                            $comment->id,
                            $comment->commentable_type,
                            $comment->commentable_id,
                            $comment->revision,
                            $mentionChanges['added'],
                            $mentionChanges['removed'],
                        );
                    }
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
