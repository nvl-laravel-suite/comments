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
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Exceptions\StaleCommentException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Support\Events\DomainEventDispatcher;

/**
 * Replaces editable comment content while retaining immutable history.
 *
 * @api
 */
final readonly class CommentUpdateWriter
{
    public function __construct(
        private CommentAccessService $access,
        private CommentContentGuard $guard,
        private CommentMutationLock $mutationLock,
        private CommentMetadataIndexWriter $metadataIndex,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
        private DomainEventDispatcher $domainEvents,
    ) {}

    /**
     * Replace editable content while preserving the previous revision.
     */
    public function update(
        Comment|string $comment,
        array $data,
        CommentActor $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment {
        $data = $this->normalizeUpdate($data);
        $commentId = $comment instanceof Comment ? $comment->id : $comment;

        return $this->mutationLock->execute(
            $commentId,
            fn (): Comment => DB::connection((CommentModelRegistry::newInstance())->getConnectionName())
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

                    if ($comment->format === CommentFormat::RichText
                        || ($data['format'] ?? null) === CommentFormat::RichText) {
                        throw new InvalidCommentMutationException(
                            'Rich comments require the rich update operation.',
                        );
                    }

                    if ($comment->revision !== $data['expectedRevision']) {
                        throw StaleCommentException::forComment($comment->id);
                    }

                    $normalizedMetadata = $this->guard->update($data, $comment->metadata);

                    $format = ! array_key_exists('format', $data)
                        ? $comment->format
                        : $data['format'];
                    $locale = ! array_key_exists('locale', $data)
                        ? $comment->locale
                        : $data['locale'];
                    $tags = ! array_key_exists('tags', $data)
                        ? $comment->tags
                        : $data['tags'];
                    $metadata = ! array_key_exists('metadata', $data)
                        ? $comment->metadata
                        : $normalizedMetadata;

                    if ($comment->body === $data['body']
                        && $comment->format === $format
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
                    $attributes = [
                        'body' => $data['body'],
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

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function normalizeUpdate(array $attributes): array
    {
        if (array_diff(array_keys($attributes), ['body', 'expectedRevision', 'format', 'locale', 'tags', 'metadata']) !== []
            || ! is_string($attributes['body'] ?? null)
            || ! is_int($attributes['expectedRevision'] ?? null)
            || $attributes['expectedRevision'] < 1) {
            throw new InvalidCommentMutationException('Comment update requires a body and positive expected revision.');
        }

        if (array_key_exists('format', $attributes)) {
            $format = $attributes['format'];
            if (is_string($format)) {
                $format = CommentFormat::tryFrom($format);
            }
            if (! $format instanceof CommentFormat) {
                throw new InvalidCommentMutationException('Comment update format is invalid.');
            }
            $attributes['format'] = $format;
        }

        if (array_key_exists('locale', $attributes)
            && $attributes['locale'] !== null
            && ! is_string($attributes['locale'])) {
            throw new InvalidCommentMutationException('Comment update locale is invalid.');
        }

        if (array_key_exists('tags', $attributes)
            && (! is_array($attributes['tags']) || ! array_is_list($attributes['tags']))) {
            throw new InvalidCommentMutationException('Comment update tags must be a list.');
        }

        if (array_key_exists('metadata', $attributes)
            && (! is_array($attributes['metadata'])
                || array_is_list($attributes['metadata']) && $attributes['metadata'] !== [])) {
            throw new InvalidCommentMutationException('Comment update metadata must be a map.');
        }

        return $attributes;
    }
}
