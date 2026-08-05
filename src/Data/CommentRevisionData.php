<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Safe comment content revision without editor identity or internal metadata.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class CommentRevisionData extends Data
{
    use DataTransform;

    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public readonly string $id,
        public readonly string $commentId,
        public readonly int $revision,
        public readonly string $body,
        public readonly CommentFormat $format,
        public readonly ?string $locale,
        #[LiteralTypeScriptType('Array<string>')]
        public readonly array $tags,
        public readonly string $createdAt,
    ) {}

    /**
     * Build a safe revision projection from a persisted snapshot.
     */
    public static function fromModel(CommentRevision $revision): self
    {
        return new self(
            id: $revision->id,
            commentId: $revision->comment_id,
            revision: $revision->revision,
            body: $revision->body,
            format: $revision->format,
            locale: $revision->locale,
            tags: is_array($revision->tags) ? $revision->tags : [],
            createdAt: $revision->created_at->format(DATE_ATOM),
        );
    }
}
