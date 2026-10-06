<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Models\Comment;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Lightweight pre-authorized comment projection excluding actor and document internals.
 *
 * @api
 */
#[TypeScript]
final class LatestCommentSummaryData extends Data
{
    use DataTransform;

    /** Retain safe scalar copy and omit anonymized content. */
    public function __construct(
        public readonly string $id,
        public readonly string|Optional $body,
        public readonly CommentFormat|Optional $format,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    /**
     * Project a bounded hydrated row without relationships or policy callbacks.
     *
     * @internal
     */
    public static function fromModel(Comment $comment): self
    {
        $omitted = Optional::create();
        $anonymized = $comment->anonymized_at !== null;

        return new self(
            $comment->id,
            $anonymized ? $omitted : $comment->body,
            $anonymized ? $omitted : $comment->format,
            $comment->created_at->format(DATE_ATOM),
            $comment->updated_at->format(DATE_ATOM),
        );
    }
}
