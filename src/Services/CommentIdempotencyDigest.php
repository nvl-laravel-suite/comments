<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use JsonException;
use LogicException;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Data\Mutations\CreateRichCommentData;
use Nvl\Comments\Enums\CommentVisibility;

/**
 * Produces a keyed digest for one canonical comment creation request.
 */
final class CommentIdempotencyDigest
{
    /**
     * Create the canonical idempotency digest service.
     */
    public function __construct(private readonly CommentMetadataRegistry $metadata) {}

    /**
     * Hash the target, actor, hierarchy, and persisted mutation fields.
     *
     * @throws JsonException
     * @throws LogicException
     */
    public function make(
        string $targetType,
        string $targetId,
        ?string $parentId,
        CommentVisibility $visibility,
        CreateCommentData $data,
        CommentActorData $actor,
    ): string {
        $payload = [
            'version' => 1,
            'target' => [
                'type' => $targetType,
                'id' => $targetId,
            ],
            'parentId' => $parentId,
            'actor' => [
                'type' => $actor->type,
                'id' => $actor->id,
                'system' => $actor->system,
            ],
            'body' => $data->body,
            'format' => $data->format->value,
            'requestedVisibility' => $data->visibility->value,
            'effectiveVisibility' => $visibility->value,
            'locale' => $data->locale,
            'tags' => $data->tags,
            'metadata' => $this->metadata->normalize($data->metadata),
        ];
        $jsonValue = json_decode(
            json_encode(
                $payload,
                JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $encoded = json_encode(
            $this->canonicalValue($jsonValue),
            JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        );

        return hash_hmac('sha256', $encoded, $this->keyBytes());
    }

    /**
     * Hash one canonical rich creation request without server-owned label snapshots.
     *
     * @throws JsonException
     * @throws LogicException
     */
    public function makeRich(
        string $targetType,
        string $targetId,
        ?string $parentId,
        CommentVisibility $visibility,
        CreateRichCommentData $data,
        CommentActorData $actor,
        string $canonicalDocument,
    ): string {
        $payload = [
            'version' => 1,
            'target' => ['type' => $targetType, 'id' => $targetId],
            'parentId' => $parentId,
            'actor' => [
                'type' => $actor->type,
                'id' => $actor->id,
                'system' => $actor->system,
            ],
            'document' => json_decode($canonicalDocument, true, 512, JSON_THROW_ON_ERROR),
            'requestedVisibility' => $data->visibility->value,
            'effectiveVisibility' => $visibility->value,
            'locale' => $data->locale,
            'tags' => $data->tags,
            'metadata' => $this->metadata->normalize($data->metadata),
        ];
        $encoded = json_encode(
            $this->canonicalValue($payload),
            JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        );

        return hash_hmac('sha256', $encoded, $this->keyBytes());
    }

    /**
     * Canonicalize nested maps while preserving list order.
     */
    private function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->canonicalValue($item),
                $value,
            );
        }

        ksort($value);

        return array_map(
            fn (mixed $item): mixed => $this->canonicalValue($item),
            $value,
        );
    }

    /**
     * Resolve a stable package-specific or application digest secret.
     *
     * @throws LogicException
     */
    private function keyBytes(): string
    {
        $configured = config('comments.idempotency.digest_key');

        if (! is_string($configured) || $configured === '') {
            $configured = config('app.key');
        }

        if (! is_string($configured) || $configured === '') {
            throw new LogicException(
                'Comment idempotency requires comments.idempotency.digest_key or app.key.',
            );
        }

        if (! str_starts_with($configured, 'base64:')) {
            return $configured;
        }

        $decoded = base64_decode(mb_substr($configured, 7), true);

        if (! is_string($decoded) || $decoded === '') {
            throw new LogicException('The configured comment idempotency digest key is invalid.');
        }

        return $decoded;
    }
}
