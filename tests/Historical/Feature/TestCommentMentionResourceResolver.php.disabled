<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Support\Collection;
use Nvl\Comments\Contracts\CommentMentionResourceResolver;
use Nvl\Comments\Data\CommentMentionResourceData;
use Nvl\Comments\ValueObjects\CommentMentionContext;

/**
 * Deterministically resolves resources for rich comment package tests.
 */
final class TestCommentMentionResourceResolver implements CommentMentionResourceResolver
{
    /**
     * Resolve known package-test resource identifiers.
     *
     * @param  list<string>  $ids
     * @return Collection<int, CommentMentionResourceData>
     */
    public function resolve(CommentMentionContext $context, array $ids): Collection
    {
        $labels = [
            'org-1' => 'Организация',
            'org-2' => 'Second Organization',
            'org-long' => str_repeat('界', 255),
        ];

        return collect($ids)
            ->filter(static fn (string $id): bool => isset($labels[$id]))
            ->map(static fn (string $id): CommentMentionResourceData => new CommentMentionResourceData(
                id: $id,
                label: $labels[$id],
            ))
            ->values();
    }

    /**
     * Suggest known package-test resources by their server-owned labels.
     *
     * @return Collection<int, CommentMentionResourceData>
     */
    public function suggest(
        CommentMentionContext $context,
        string $query,
        int $limit,
    ): Collection {
        return $this->resolve($context, ['org-1', 'org-2', 'org-long'])
            ->filter(static fn (CommentMentionResourceData $resource): bool => is_string(
                $resource->label,
            ) && str_contains(
                mb_strtolower($resource->label),
                mb_strtolower($query),
            ))
            ->take($limit)
            ->values();
    }
}
