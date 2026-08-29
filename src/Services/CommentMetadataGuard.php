<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use JsonException;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Support\CommentsConfiguration;

/**
 * Enforces encoded limits around registry-normalized comment metadata.
 */
final readonly class CommentMetadataGuard
{
    /**
     * Create the metadata mutation guard.
     */
    public function __construct(private CommentMetadataRegistry $registry) {}

    /**
     * Normalize and bound one complete metadata record.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    public function normalize(array $metadata, ?array $existing = null): array
    {
        $normalized = $this->registry->normalize($metadata, $existing);

        try {
            $encoded = json_encode(
                $normalized,
                JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidCommentMutationException(
                'Comment metadata must be valid JSON data.',
                previous: $exception,
            );
        }

        $maximumBytes = min(65_536, CommentsConfiguration::positiveInteger(
            'comments.metadata.maximum_bytes',
            16_384,
        ));

        if (strlen($encoded) > $maximumBytes) {
            throw new InvalidCommentMutationException(
                'Comment metadata exceeds the metadata byte limit.',
            );
        }

        return $normalized;
    }
}
