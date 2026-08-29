<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Support\Collection;
use Nvl\Comments\Data\CommentMentionResourceData;
use Nvl\Comments\ValueObjects\CommentMentionContext;

/**
 * Resolves one registered mention resource type in bounded batches.
 */
interface CommentMentionResourceResolver
{
    /**
     * Resolve authorized resources for the requested opaque identifiers.
     *
     * @param  list<string>  $ids
     * @return Collection<int, CommentMentionResourceData>
     */
    public function resolve(CommentMentionContext $context, array $ids): Collection;
}
