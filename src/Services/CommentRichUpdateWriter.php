<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Support\Facades\DB;
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
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Comments\ValueObjects\CommentMentionContext;
use Nvl\Support\Events\DomainEventDispatcher;

/**
 * Replaces rich comment content and mention rows while retaining history.
 *
 * @internal
 */
final readonly class CommentRichUpdateWriter
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
        private DomainEventDispatcher $domainEvents,
    ) {}

    /**
     * Replace authorized rich content with optimistic concurrency protection.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(
        Comment|string $comment,
        array $data,
        CommentActor $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment {
        $data = $this->normalizeUpdate($data);
        $commentId = $comment instanceof Comment ? $comment->id : $comment;
        $inputDocument = $this->documents->normalizeUnresolved($data['document']);

        return $this->mutationLock->execute(
            $commentId,
            fn (): Comment => DB::connection((CommentModelRegistry::newInstance())->getConnectionName())
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

                    if ($comment->revision !== $data['expected_revision']) {
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
                    $locale = ! array_key_exists('locale', $data)
                        ? $comment->locale
                        : $data['locale'];
                    $tags = ! array_key_exists('tags', $data)
                        ? $comment->tags
                        : $data['tags'];
                    $metadata = ! array_key_exists('metadata', $data)
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

                    $revision = CommentRecordWriter::create(CommentRevision::class, ['comment_id' => $comment->id,
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

                    $configuredStatus = config('nvl-comments.moderation.edited_status', 'pending');
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
                        $this->domainEvents->dispatch(new CommentMentionsChanged(
                            $comment->id,
                            $comment->commentable_type,
                            $comment->commentable_id,
                            $comment->revision,
                            $mentionChanges['added'],
                            $mentionChanges['removed'],
                        ), $comment->getConnection());
                    }
                    $this->domainEvents->dispatch(new CommentChanged(
                        $comment->id,
                        $comment->commentable_type,
                        $comment->commentable_id,
                        CommentChangeOperation::Updated,
                        $comment->revision,
                        $actor,
                    ), $comment->getConnection());

                    return $comment->refresh();
                }, attempts: CommentsConfiguration::positiveInteger(
                    'nvl-comments.transactions.attempts',
                    3,
                )),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{document: array{version: int, blocks: array<array-key, mixed>}, expected_revision: int, locale?: ?string, tags?: list<mixed>, metadata?: array<string, mixed>}
     */
    private function normalizeUpdate(array $attributes): array
    {
        $document = $attributes['document'] ?? null;
        if (array_diff(array_keys($attributes), ['document', 'expected_revision', 'locale', 'tags', 'metadata']) !== []
            || ! is_array($document)
            || ! is_int($document['version'] ?? null)
            || ! is_array($document['blocks'] ?? null)
            || ! is_int($attributes['expected_revision'] ?? null)
            || $attributes['expected_revision'] < 1
            || (array_key_exists('locale', $attributes)
                && $attributes['locale'] !== null
                && ! is_string($attributes['locale']))
            || (array_key_exists('tags', $attributes)
                && (! is_array($attributes['tags']) || ! array_is_list($attributes['tags'])))
            || (array_key_exists('metadata', $attributes)
                && (! is_array($attributes['metadata'])
                    || array_is_list($attributes['metadata']) && $attributes['metadata'] !== []))) {
            throw new InvalidCommentMutationException('Rich comment update attributes are invalid.');
        }

        $normalized = ['document' => $document, 'expected_revision' => $attributes['expected_revision']];
        if (array_key_exists('locale', $attributes)) {
            $normalized['locale'] = $attributes['locale'];
        }
        if (array_key_exists('tags', $attributes)) {
            $normalized['tags'] = $attributes['tags'];
        }
        if (array_key_exists('metadata', $attributes)) {
            $metadataMap = [];
            foreach ($attributes['metadata'] as $key => $value) {
                if (! is_string($key)) {
                    throw new InvalidCommentMutationException('Comment metadata keys must be strings.');
                }
                $metadataMap[$key] = $value;
            }
            $normalized['metadata'] = $metadataMap;
        }

        return $normalized;
    }
}
