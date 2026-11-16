<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Dom\HTMLDocument;
use Dom\Node;
use Nvl\Comments\Contracts\CommentContentCodec;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;

/**
 * Preserves HTML source and projects supported text markup without rendering it.
 *
 * @api
 */
final class HtmlCommentCodec implements CommentContentCodec
{
    private const array Containers = ['html', 'head', 'body', 'a', 'span', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'code', 'mark', 'sub', 'sup'];

    private const array Blocks = ['p', 'div', 'section', 'blockquote', 'ol', 'ul', 'li', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    public function identifier(): string
    {
        return 'html';
    }

    public function version(): int
    {
        return 1;
    }

    /** {@inheritDoc} */
    public function project(string|array $payload): array
    {
        if (! is_string($payload) || trim($payload) === '') {
            throw new InvalidCommentMutationException('HTML comment content requires a non-empty string.');
        }
        if (! class_exists(HTMLDocument::class)) {
            throw new InvalidCommentMutationException('HTML comment projection requires the PHP DOM extension.');
        }

        $document = HTMLDocument::createFromString($payload, LIBXML_NOERROR, 'UTF-8');
        $root = $document->documentElement;
        $unsupported = [];
        $body = $root === null ? '' : $this->text($root, $unsupported, 0);

        return ['body' => trim($body), 'unsupported' => array_keys($unsupported)];
    }

    /** @param array<string,true> $unsupported */
    private function text(Node $node, array &$unsupported, int $depth): string
    {
        if ($depth > 32) {
            $unsupported['nesting'] = true;

            return '';
        }
        if ($node->nodeType === XML_TEXT_NODE) {
            return $node->textContent ?? '';
        }
        if ($node->nodeType === XML_COMMENT_NODE) {
            return '';
        }
        $tag = strtolower($node->nodeName);
        if ($tag === 'br') {
            return "\n";
        }
        $block = in_array($tag, self::Blocks, true);
        if (! $block && ! in_array($tag, self::Containers, true)) {
            $unsupported[$tag] = true;

            return '';
        }

        $body = '';
        foreach ($node->childNodes as $child) {
            $body .= $this->text($child, $unsupported, $depth + 1);
        }

        return $block ? "\n".trim($body)."\n" : $body;
    }
}
