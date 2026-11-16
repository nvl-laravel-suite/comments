<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Nvl\Comments\Contracts\CommentContentCodec;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;

/**
 * Projects text blocks while preserving their original structure and formatting data.
 *
 * @api
 */
final class RichBlockCommentCodec implements CommentContentCodec
{
    private const array Blocks = ['paragraph', 'heading', 'quote', 'blockquote', 'bulletListItem', 'numberedListItem', 'checkListItem', 'listItem', 'bulletList', 'orderedList', 'codeBlock'];

    public function identifier(): string
    {
        return 'rich-blocks';
    }

    public function version(): int
    {
        return 1;
    }

    /** {@inheritDoc} */
    public function project(string|array $payload): array
    {
        $decoded = is_string($payload) ? json_decode($payload, true, 32) : $payload;
        $blocks = is_array($decoded) && ! array_is_list($decoded) ? ($decoded['blocks'] ?? null) : $decoded;
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            throw new InvalidCommentMutationException('Rich block comment content requires a list of blocks.');
        }

        $unsupported = [];
        $body = $this->text($blocks, $unsupported, 0);

        return ['body' => trim($body), 'unsupported' => array_keys($unsupported)];
    }

    /** @param array<string,true> $unsupported */
    private function text(mixed $node, array &$unsupported, int $depth): string
    {
        if ($depth > 32) {
            $unsupported['nesting'] = true;

            return '';
        }
        if (is_string($node)) {
            return $node;
        }
        if (! is_array($node)) {
            $unsupported['unknown'] = true;

            return '';
        }
        if (array_is_list($node)) {
            $body = '';
            foreach ($node as $child) {
                $body .= $this->text($child, $unsupported, $depth + 1);
            }

            return $body;
        }
        $type = $node['type'] ?? null;
        if ($type === 'text' && is_string($node['text'] ?? null)) {
            return $node['text'];
        }
        if ($type === 'hardBreak') {
            return "\n";
        }
        $block = in_array($type, self::Blocks, true);
        if (! $block && $type !== 'link') {
            $unsupported[is_string($type) && ! is_numeric($type) ? $type : 'unknown'] = true;

            return '';
        }

        $body = $this->text($node['content'] ?? [], $unsupported, $depth + 1)
            .$this->text($node['children'] ?? [], $unsupported, $depth + 1);

        return $block ? $body."\n" : $body;
    }
}
