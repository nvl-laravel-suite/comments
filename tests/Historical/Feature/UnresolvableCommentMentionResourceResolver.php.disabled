<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Support\Collection;
use Nvl\Comments\Contracts\CommentMentionResourceResolver;
use Nvl\Comments\Data\CommentMentionResourceData;
use Nvl\Comments\ValueObjects\CommentMentionContext;

/**
 * Deliberately requires an unbound scalar to exercise registration diagnostics.
 */
final readonly class UnresolvableCommentMentionResourceResolver implements CommentMentionResourceResolver
{
    public function __construct(public string $requiredConfiguration) {}

    /**
     * @param  list<string>  $ids
     * @return Collection<int, CommentMentionResourceData>
     */
    public function resolve(CommentMentionContext $context, array $ids): Collection
    {
        return collect($this->noResources());
    }

    /**
     * @return Collection<int, CommentMentionResourceData>
     */
    public function suggest(
        CommentMentionContext $context,
        string $query,
        int $limit,
    ): Collection {
        return collect($this->noResources());
    }

    /**
     * @return list<CommentMentionResourceData>
     */
    private function noResources(): array
    {
        return [];
    }
}
