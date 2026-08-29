<?php

declare(strict_types=1);

namespace Nvl\Comments\Data\Queries;

use InvalidArgumentException;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Support\CommentsConfiguration;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Bounded selectors for resolving one latest target comment.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class CommentSelectorData extends Data
{
    /** @var list<string> */
    #[LiteralTypeScriptType('Array<string>')]
    public readonly array $tags;

    /** @var array<string, string|int|bool|null> */
    #[LiteralTypeScriptType('Record<string, string | number | boolean | null>')]
    public readonly array $metadataEquals;

    /**
     * Create one exact tag-and-status selector.
     *
     * @param  array<array-key, mixed>  $tags
     * @param  array<array-key, mixed>  $metadataEquals
     */
    public function __construct(
        array $tags = [],
        array $metadataEquals = [],
        public readonly ?CommentStatus $status = null,
    ) {
        if (! array_is_list($tags)) {
            throw new InvalidArgumentException('Comment selector tags must be a list.');
        }

        $maximumTags = min(
            20,
            CommentsConfiguration::positiveInteger(
                'comments.content.maximum_tags',
                20,
            ),
        );

        if (count($tags) > $maximumTags) {
            throw new InvalidArgumentException(
                "Comment selectors may contain at most {$maximumTags} tags.",
            );
        }

        $seen = [];
        $validatedTags = [];

        foreach ($tags as $tag) {
            if (! is_string($tag)
                || ! mb_check_encoding($tag, 'UTF-8')
                || preg_match('/\S/u', $tag) !== 1
                || mb_strlen($tag) > 64) {
                throw new InvalidArgumentException(
                    'Comment selector tags must be valid, non-blank UTF-8 strings containing at most 64 characters.',
                );
            }

            if (isset($seen[$tag])) {
                throw new InvalidArgumentException('Comment selector tags must be distinct.');
            }

            $seen[$tag] = true;
            $validatedTags[] = $tag;
        }

        $this->tags = $validatedTags;

        if (count($metadataEquals) > 10) {
            throw new InvalidArgumentException(
                'Comment selectors may contain at most 10 metadata criteria.',
            );
        }

        $validatedMetadata = [];

        foreach ($metadataEquals as $alias => $value) {
            if (! is_string($alias)
                || preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $alias) !== 1
                || (! is_string($value)
                    && ! is_int($value)
                    && ! is_bool($value)
                    && $value !== null)) {
                throw new InvalidArgumentException(
                    'Comment metadata selectors must use scalar registered aliases.',
                );
            }

            $validatedMetadata[$alias] = $value;
        }

        $this->metadataEquals = $validatedMetadata;
    }
}
