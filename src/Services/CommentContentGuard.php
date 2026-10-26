<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Support\CommentsConfiguration;

/**
 * Enforces content, tag, format, and metadata limits at the domain boundary.
 */
final class CommentContentGuard
{
    /**
     * Create the content mutation guard.
     */
    public function __construct(private readonly CommentMetadataGuard $metadata) {}

    /**
     * Validate create content and return normalized metadata for persistence.
     *
     * @param  array{format: CommentFormat, visibility: CommentVisibility, locale: ?string, parentId: ?string, idempotencyKey: ?string, tags: list<mixed>, metadata: array<string, mixed>, body: string}  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $this->assertBody($data['body']);
        $this->assertFormat($data['format']->value);
        $this->assertLocale($data['locale']);
        $this->assertTags($data['tags']);

        return $this->metadata->normalize($data['metadata']);
    }

    /**
     * Validate update content and return presence-aware normalized metadata.
     *
     * @param  array<string, mixed>|null  $existingMetadata
     * @param  array{body: string, expectedRevision: int, format?: CommentFormat, locale?: ?string, tags?: list<mixed>, metadata?: array<string, mixed>}  $data
     * @return array<string, mixed>
     */
    public function update(array $data, ?array $existingMetadata = null): array
    {
        $this->assertBody($data['body']);

        if (array_key_exists('format', $data)) {
            $this->assertFormat($data['format']->value);
        }

        if (array_key_exists('locale', $data)) {
            $this->assertLocale($data['locale']);
        }

        if (array_key_exists('tags', $data)) {
            $this->assertTags($data['tags']);
        }

        if (array_key_exists('metadata', $data)) {
            return $this->metadata->normalize($data['metadata'], $existingMetadata);
        }

        return $existingMetadata ?? [];
    }

    /**
     * Validate rich create fields and return normalized metadata for persistence.
     *
     * @param  (array{format: CommentFormat, visibility: CommentVisibility, locale: ?string, parentId: ?string, idempotencyKey: ?string, tags: list<mixed>, metadata: array<string, mixed>, body: string}|array{format: CommentFormat, visibility: CommentVisibility, locale: ?string, parentId: ?string, idempotencyKey: ?string, tags: list<mixed>, metadata: array<string, mixed>, document: array{version: int, blocks: array<array-key, mixed>}})  $data
     * @return array<string, mixed>
     */
    public function createRich(array $data, string $body): array
    {
        $this->assertDerivedBody($body);
        $this->assertLocale($data['locale']);
        $this->assertTags($data['tags']);

        return $this->metadata->normalize($data['metadata']);
    }

    /**
     * Validate rich update fields and return presence-aware normalized metadata.
     *
     * @param  array<string, mixed>|null  $existingMetadata
     * @param  array{document: array{version: int, blocks: array<array-key, mixed>}, expectedRevision: int, locale?: ?string, tags?: list<mixed>, metadata?: array<string, mixed>}  $data
     * @return array<string, mixed>
     */
    public function updateRich(
        array $data,
        string $body,
        ?array $existingMetadata = null,
    ): array {
        $this->assertDerivedBody($body);

        if (array_key_exists('locale', $data)) {
            $this->assertLocale($data['locale']);
        }

        if (array_key_exists('tags', $data)) {
            $this->assertTags($data['tags']);
        }

        if (array_key_exists('metadata', $data)) {
            return $this->metadata->normalize($data['metadata'], $existingMetadata);
        }

        return $existingMetadata ?? [];
    }

    private function assertBody(string $body): void
    {
        if (! mb_check_encoding($body, 'UTF-8') || preg_match('/\S/u', $body) !== 1) {
            throw new InvalidCommentMutationException(
                'Comment content must contain valid, non-blank UTF-8 text.',
            );
        }

        $maximumBytes = CommentsConfiguration::positiveInteger(
            'nvl-comments.content.maximum_bytes',
            20_000,
        );

        if (strlen($body) > $maximumBytes) {
            throw new InvalidCommentMutationException(
                "Comment content exceeds {$maximumBytes} bytes.",
            );
        }
    }

    /**
     * Require a valid non-blank server-derived rich-text compatibility body.
     */
    private function assertDerivedBody(string $body): void
    {
        if (! mb_check_encoding($body, 'UTF-8') || preg_match('/\S/u', $body) !== 1) {
            throw new InvalidCommentMutationException(
                'Derived rich comment content must contain valid, non-blank UTF-8 text.',
            );
        }
    }

    private function assertFormat(string $format): void
    {
        if ($format === CommentFormat::RichText->value) {
            throw new InvalidCommentMutationException(
                'Rich comment content requires the dedicated rich mutation actions.',
            );
        }

        $allowedFormats = config('nvl-comments.content.allowed_formats', ['plain', 'markdown']);

        if (! is_array($allowedFormats) || ! in_array($format, $allowedFormats, true)) {
            throw new InvalidCommentMutationException(
                "Comment format [{$format}] is not enabled.",
            );
        }
    }

    private function assertLocale(?string $locale): void
    {
        if ($locale !== null
            && (! mb_check_encoding($locale, 'UTF-8') || mb_strlen($locale) > 35)) {
            throw new InvalidCommentMutationException(
                'Comment locale must be valid UTF-8 containing at most 35 characters.',
            );
        }
    }

    /**
     * @param  array<int, mixed>  $tags
     */
    private function assertTags(array $tags): void
    {
        if (! array_is_list($tags)) {
            throw new InvalidCommentMutationException(
                'Comment tags must be a list.',
            );
        }

        $maximumTags = CommentsConfiguration::positiveInteger(
            'nvl-comments.content.maximum_tags',
            20,
        );

        if (count($tags) > $maximumTags) {
            throw new InvalidCommentMutationException(
                "Comments may contain at most {$maximumTags} tags.",
            );
        }

        $uniqueTags = [];

        foreach ($tags as $tag) {
            if (! is_string($tag)
                || ! mb_check_encoding($tag, 'UTF-8')
                || preg_match('/\S/u', $tag) !== 1
                || mb_strlen($tag) > 64) {
                throw new InvalidCommentMutationException(
                    'Comment tags must be valid, non-blank UTF-8 strings containing at most 64 characters.',
                );
            }

            if (isset($uniqueTags[$tag])) {
                throw new InvalidCommentMutationException('Comment tags must be distinct.');
            }

            $uniqueTags[$tag] = true;
        }
    }
}
