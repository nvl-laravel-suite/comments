<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;

/**
 * Canonical current mention reference compiled from a stored rich document.
 */
final class CommentMentionReferenceData extends Data
{
    use DataTransform;

    /**
     * Create one ordered normalized mention reference.
     */
    public function __construct(
        public readonly string $tokenId,
        public readonly string $resourceAlias,
        public readonly string $resourceId,
        public readonly string $labelSnapshot,
        public readonly int $position,
    ) {}
}
