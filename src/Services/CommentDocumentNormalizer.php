<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Support\Str;
use JsonException;
use Nvl\Comments\Data\CommentMentionReferenceData;
use Nvl\Comments\Data\Mutations\CommentDocumentData;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Comments\ValueObjects\CommentMentionContext;
use Symfony\Polyfill\Intl\Normalizer\Normalizer;

/**
 * Validates, resolves, and canonically compiles version-one rich documents.
 */
final readonly class CommentDocumentNormalizer
{
    /**
     * Create the rich-document compiler.
     */
    public function __construct(private CommentMentionResourceRegistry $resources) {}

    /**
     * Validate client input, resolve every mention, and add server-owned labels.
     */
    public function normalizeInput(
        CommentDocumentData $document,
        CommentMentionContext $context,
    ): CommentDocumentData {
        if (config('comments.mentions.enabled', false) !== true) {
            throw new InvalidCommentMutationException(
                'Rich comment mentions are not enabled.',
            );
        }

        $inputBlocks = $this->validate($document, allowSnapshots: false);
        $normalized = new CommentDocumentData(
            version: 1,
            blocks: $inputBlocks,
        );
        $mentionsByAlias = [];

        foreach ($this->references($normalized, requireSnapshots: false) as $reference) {
            $mentionsByAlias[$reference->resourceAlias][] = $reference->resourceId;
        }

        $labels = [];

        foreach ($mentionsByAlias as $alias => $ids) {
            $resolved = $this->resources->resolve($alias, $context, array_values(array_unique($ids)));

            foreach ($resolved as $resource) {
                $labels[$alias][$resource->id] = $this->normalizeText($resource->label);
            }
        }

        $blocks = [];

        foreach ($inputBlocks as $block) {
            $children = [];

            foreach ($block['children'] as $node) {
                if ($node['type'] === 'mention') {
                    $node['labelSnapshot'] = $labels[$node['resource']][$node['id']];
                }

                $children[] = $node;
            }

            $blocks[] = ['type' => 'paragraph', 'children' => $children];
        }

        return new CommentDocumentData(version: 1, blocks: $blocks);
    }

    /**
     * Validate and canonicalize client document structure without resource resolution.
     */
    public function normalizeUnresolved(CommentDocumentData $document): CommentDocumentData
    {
        return new CommentDocumentData(
            version: 1,
            blocks: $this->validate($document, allowSnapshots: false),
        );
    }

    /**
     * Validate a stored historical document without live resource resolution.
     *
     * @param  array<string, mixed>  $document
     */
    public function normalizeStored(array $document): CommentDocumentData
    {
        if (! $this->hasExactKeys($document, ['version', 'blocks'])
            || ! is_int($document['version'] ?? null)
            || ! is_array($document['blocks'] ?? null)) {
            throw new InvalidCommentMutationException(
                'Stored comment document is not a valid version-one document.',
            );
        }

        $blocks = $document['blocks'];

        return new CommentDocumentData(
            version: 1,
            blocks: $this->validate(
                new CommentDocumentData($document['version'], $blocks),
                allowSnapshots: true,
            ),
        );
    }

    /**
     * Return a deterministic plain-text compatibility projection.
     */
    public function body(CommentDocumentData $document): string
    {
        $paragraphs = [];

        foreach ($this->validate($document, allowSnapshots: true) as $block) {
            $body = '';

            foreach ($block['children'] as $node) {
                $body .= match ($node['type']) {
                    'text' => $node['text'],
                    'mention' => '@'.$node['labelSnapshot'],
                    'hard_break' => "\n",
                    default => throw new InvalidCommentMutationException(
                        'Comment documents contain an unknown node type.',
                    ),
                };
            }

            $paragraphs[] = $body;
        }

        return implode("\n\n", $paragraphs);
    }

    /**
     * Return ordered mention references from one normalized document.
     *
     * @return list<CommentMentionReferenceData>
     */
    public function references(
        CommentDocumentData $document,
        bool $requireSnapshots = true,
    ): array {
        $references = [];
        $position = 0;

        foreach ($this->validate($document, allowSnapshots: $requireSnapshots) as $block) {
            foreach ($block['children'] as $node) {
                if ($node['type'] !== 'mention') {
                    continue;
                }

                $label = $node['labelSnapshot'] ?? '';

                if ($requireSnapshots && $label === '') {
                    throw new InvalidCommentMutationException(
                        'Stored comment mentions require label snapshots.',
                    );
                }

                $references[] = new CommentMentionReferenceData(
                    tokenId: $node['tokenId'],
                    resourceAlias: $node['resource'],
                    resourceId: $node['id'],
                    labelSnapshot: $label,
                    position: $position,
                );
                $position++;
            }
        }

        return $references;
    }

    /**
     * Return the canonical JSON representation used by persistence digests.
     *
     * @throws JsonException
     */
    public function canonicalJson(CommentDocumentData $document): string
    {
        return json_encode(
            ['version' => $document->version, 'blocks' => $document->blocks],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Return the canonical storage array for one normalized document.
     *
     * @return array{version: int, blocks: list<array<string, mixed>>}
     */
    public function toArray(CommentDocumentData $document): array
    {
        return [
            'version' => 1,
            'blocks' => $this->validate($document, allowSnapshots: true),
        ];
    }

    /**
     * Validate and canonicalize one document with strict node-key ownership.
     */
    /**
     * Validate one document into canonical block and string-node maps.
     *
     * @return list<array{type: string, children: list<array<string, string>>}>
     */
    private function validate(
        CommentDocumentData $document,
        bool $allowSnapshots,
    ): array {
        if ($document->version !== 1 || ! array_is_list($document->blocks)) {
            throw new InvalidCommentMutationException(
                'Comment documents must use version one and a block list.',
            );
        }

        $maximumBlocks = min(250, CommentsConfiguration::positiveInteger(
            'comments.rich_text.maximum_blocks',
            100,
        ));
        $maximumNodes = min(1_000, CommentsConfiguration::positiveInteger(
            'comments.rich_text.maximum_nodes',
            500,
        ));

        if ($document->blocks === [] || count($document->blocks) > $maximumBlocks) {
            throw new InvalidCommentMutationException(
                'Comment document block count is outside the configured bounds.',
            );
        }

        $blocks = [];
        $nodeCount = 0;
        $mentionCount = 0;
        $resourceAliases = [];
        $tokenIds = [];

        foreach ($document->blocks as $block) {
            if (! is_array($block)
                || ! $this->hasExactKeys($block, ['type', 'children'])
                || ($block['type'] ?? null) !== 'paragraph'
                || ! is_array($block['children'] ?? null)
                || ! array_is_list($block['children'])) {
                throw new InvalidCommentMutationException(
                    'Comment documents contain an invalid paragraph block.',
                );
            }

            $children = [];

            foreach ($block['children'] as $node) {
                $nodeCount++;

                if ($nodeCount > $maximumNodes || ! is_array($node)) {
                    throw new InvalidCommentMutationException(
                        'Comment document node count is outside the configured bounds.',
                    );
                }

                $type = $node['type'] ?? null;

                if ($type === 'text') {
                    if (! $this->hasExactKeys($node, ['type', 'text'])
                        || ! is_string($node['text'])) {
                        throw new InvalidCommentMutationException(
                            'Comment documents contain an invalid text node.',
                        );
                    }

                    $text = $this->normalizeText($node['text']);

                    if ($text === '') {
                        throw new InvalidCommentMutationException(
                            'Comment document text nodes must not be empty.',
                        );
                    }

                    $children[] = ['type' => 'text', 'text' => $text];

                    continue;
                }

                if ($type === 'hard_break') {
                    if (! $this->hasExactKeys($node, ['type'])) {
                        throw new InvalidCommentMutationException(
                            'Comment documents contain an invalid hard-break node.',
                        );
                    }

                    $children[] = ['type' => 'hard_break'];

                    continue;
                }

                if ($type !== 'mention') {
                    throw new InvalidCommentMutationException(
                        'Comment documents contain an unknown node type.',
                    );
                }

                $expectedKeys = $allowSnapshots
                    ? ['type', 'tokenId', 'resource', 'id', 'labelSnapshot']
                    : ['type', 'tokenId', 'resource', 'id'];

                if (! $this->hasExactKeys($node, $expectedKeys)
                    || ! is_string($node['tokenId'] ?? null)
                    || ! is_string($node['resource'] ?? null)
                    || (! is_string($node['id'] ?? null) && ! is_int($node['id'] ?? null))
                    || ! Str::isUuid($node['tokenId'])) {
                    throw new InvalidCommentMutationException(
                        'Comment documents contain an invalid mention node.',
                    );
                }

                $resource = $node['resource'];
                $resourceId = (string) $node['id'];

                if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $resource) !== 1
                    || ! mb_check_encoding($resourceId, 'UTF-8')
                    || preg_match('/\S/u', $resourceId) !== 1
                    || mb_strlen($resourceId) > 255
                    || isset($tokenIds[$node['tokenId']])) {
                    throw new InvalidCommentMutationException(
                        'Comment documents contain an invalid or duplicate mention token.',
                    );
                }

                $tokenIds[$node['tokenId']] = true;
                $resourceAliases[$resource] = true;
                $mentionCount++;
                $normalizedMention = [
                    'type' => 'mention',
                    'tokenId' => strtolower($node['tokenId']),
                    'resource' => $resource,
                    'id' => $resourceId,
                ];

                if ($allowSnapshots) {
                    if (! is_string($node['labelSnapshot'])
                        || preg_match('/\S/u', $node['labelSnapshot']) !== 1
                        || mb_strlen($node['labelSnapshot']) > 255) {
                        throw new InvalidCommentMutationException(
                            'Stored comment mention labels are invalid.',
                        );
                    }

                    $normalizedMention['labelSnapshot'] = $this->normalizeText(
                        $node['labelSnapshot'],
                    );
                }

                $children[] = $normalizedMention;
            }

            $blocks[] = ['type' => 'paragraph', 'children' => $children];
        }

        $maximumMentions = min(100, CommentsConfiguration::positiveInteger(
            'comments.mentions.maximum_per_comment',
            25,
        ));
        $maximumResources = min(20, CommentsConfiguration::positiveInteger(
            'comments.mentions.maximum_resource_types_per_comment',
            10,
        ));

        if ($mentionCount > $maximumMentions || count($resourceAliases) > $maximumResources) {
            throw new InvalidCommentMutationException(
                'Comment document mention count is outside the configured bounds.',
            );
        }

        $normalized = new CommentDocumentData(version: 1, blocks: $blocks);
        $maximumBytes = min(131_072, CommentsConfiguration::positiveInteger(
            'comments.rich_text.maximum_bytes',
            32_768,
        ));

        if (strlen($this->canonicalJson($normalized)) > $maximumBytes
            || preg_match('/\S/u', $this->unresolvedBody($blocks)) !== 1) {
            throw new InvalidCommentMutationException(
                'Comment document is empty or exceeds the configured byte bound.',
            );
        }

        return $blocks;
    }

    /**
     * Return text sufficient to reject structurally non-empty but blank documents.
     */
    /**
     * @param  list<array{type: string, children: list<array<string, string>>}>  $blocks
     */
    private function unresolvedBody(array $blocks): string
    {
        $body = '';

        foreach ($blocks as $block) {
            foreach ($block['children'] as $node) {
                if ($node['type'] === 'text') {
                    $body .= $node['text'];
                } elseif ($node['type'] === 'mention') {
                    $body .= '@mention';
                }
            }
        }

        return $body;
    }

    /**
     * Normalize valid UTF-8 text to NFC with canonical newlines.
     */
    private function normalizeText(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidCommentMutationException(
                'Comment document text must be valid UTF-8.',
            );
        }

        $normalized = Normalizer::normalize(
            str_replace(["\r\n", "\r"], "\n", $text),
            Normalizer::FORM_C,
        );

        if (! is_string($normalized)) {
            throw new InvalidCommentMutationException(
                'Comment document text could not be normalized.',
            );
        }

        return $normalized;
    }

    /**
     * Determine whether one map owns exactly the allowlisted keys in any input order.
     *
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $expected
     */
    private function hasExactKeys(array $value, array $expected): bool
    {
        return count($value) === count($expected)
            && array_diff(array_keys($value), $expected) === [];
    }
}
