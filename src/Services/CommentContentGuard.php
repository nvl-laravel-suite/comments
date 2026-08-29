<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Data\Mutations\UpdateCommentData;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Support\CommentsConfiguration;
use Spatie\LaravelData\Optional;

/**
 * Enforces content, tag, format, and metadata limits beyond HTTP validation.
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
     * @return array<string, mixed>
     */
    public function create(CreateCommentData $data): array
    {
        $this->assertBody($data->body);
        $this->assertFormat($data->format->value);
        $this->assertLocale($data->locale);
        $this->assertTags($data->tags);

        return $this->metadata->normalize($data->metadata);
    }

    /**
     * Validate update content and return presence-aware normalized metadata.
     *
     * @param  array<string, mixed>|null  $existingMetadata
     * @return array<string, mixed>
     */
    public function update(UpdateCommentData $data, ?array $existingMetadata = null): array
    {
        $this->assertBody($data->body);

        if (! $data->format instanceof Optional) {
            $this->assertFormat($data->format->value);
        }

        if (! $data->locale instanceof Optional) {
            $this->assertLocale($data->locale);
        }

        if (! $data->tags instanceof Optional) {
            $this->assertTags($data->tags);
        }

        if (! $data->metadata instanceof Optional) {
            return $this->metadata->normalize($data->metadata, $existingMetadata);
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
            'comments.content.maximum_bytes',
            20_000,
        );

        if (strlen($body) > $maximumBytes) {
            throw new InvalidCommentMutationException(
                "Comment content exceeds {$maximumBytes} bytes.",
            );
        }
    }

    private function assertFormat(string $format): void
    {
        $allowedFormats = config('comments.content.allowed_formats', ['plain', 'markdown']);

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
            'comments.content.maximum_tags',
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
