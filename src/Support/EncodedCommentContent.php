<?php

declare(strict_types=1);

namespace Nvl\Comments\Support;

use JsonException;
use Nvl\Comments\Contracts\CommentContentCodec;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;

/**
 * Retains the source envelope alongside its plain-text projection.
 *
 * @internal
 *
 * @phpstan-type Envelope array{codec:string, version:int, payload:string|array<array-key,mixed>}
 */
final readonly class EncodedCommentContent
{
    /** @param Envelope $document */
    private function __construct(public string $body, public array $document, public string $canonical) {}

    /**
     * Bound and validate source data before calling a codec or building a digest.
     *
     * @param  string|array<array-key,mixed>  $payload
     */
    public static function capture(CommentContentCodec $codec, string|array $payload): self
    {
        $identifier = $codec->identifier();
        $version = $codec->version();
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $identifier) !== 1 || $version < 1) {
            throw new InvalidCommentMutationException('Comment content codecs require a stable identifier and positive version.');
        }

        self::assertData($payload, 0);
        $document = ['codec' => $identifier, 'version' => $version, 'payload' => $payload];
        try {
            $canonical = json_encode($document, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 36);
        } catch (JsonException) {
            throw new InvalidCommentMutationException('Encoded comment content must be valid UTF-8 JSON data.');
        }
        if (strlen($canonical) > 262144) {
            throw new InvalidCommentMutationException('Encoded comment content exceeds 256 KiB.');
        }

        $projection = $codec->project($payload);
        if ($projection['unsupported'] !== []) {
            $nodes = array_unique(array_map(static fn (string $type): string => preg_match('/\A[a-zA-Z][a-zA-Z0-9_.:-]{0,63}\z/', $type) === 1 ? $type : 'unknown', $projection['unsupported']));
            throw new InvalidCommentMutationException('Unsupported comment content nodes: '.implode(', ', array_slice($nodes, 0, 20)).'.');
        }

        return new self($projection['body'], $document, $canonical);
    }

    /**
     * Recognize a stored private source envelope written by encoded creation or adoption.
     */
    public static function isEnvelope(mixed $document): bool
    {
        if (! is_array($document)) {
            return false;
        }
        $keys = array_keys($document);
        sort($keys);

        return $keys === ['codec', 'payload', 'version']
            && is_string($document['codec'])
            && preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $document['codec']) === 1
            && is_int($document['version'])
            && $document['version'] >= 1
            && (is_string($document['payload']) || is_array($document['payload']));
    }

    private static function assertData(mixed $value, int $depth): void
    {
        if ($depth > 32 || (! is_array($value) && ! is_scalar($value) && $value !== null)
            || (is_string($value) && str_contains($value, "\0"))) {
            throw new InvalidCommentMutationException('Encoded comment content must be bounded JSON data without NUL bytes.');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertData($item, $depth + 1);
            }
        }
    }
}
