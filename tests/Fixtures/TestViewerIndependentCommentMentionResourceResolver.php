<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Support\Collection;
use Nvl\Comments\Contracts\CommentMentionResourceResolver;
use Nvl\Comments\Contracts\ViewerIndependentCommentMentionResource;
use Nvl\Comments\Data\CommentMentionResourceData;
use Nvl\Comments\ValueObjects\CommentMentionContext;

/**
 * Resolves a viewer-independent custom mention catalog for public projection tests.
 */
final class TestViewerIndependentCommentMentionResourceResolver implements CommentMentionResourceResolver, ViewerIndependentCommentMentionResource
{
    /**
     * Resolve requested public resources.
     *
     * @param  list<string>  $ids
     * @return Collection<int, CommentMentionResourceData>
     */
    public function resolve(CommentMentionContext $context, array $ids): Collection
    {
        return collect($ids)->map(
            static fn (string $id): CommentMentionResourceData => new CommentMentionResourceData(
                id: $id,
                label: "Public {$id}",
                fields: ['kind' => 'public'],
                url: '/public-resources/'.rawurlencode($id),
            ),
        );
    }

    /**
     * Suggest public resources through the same bounded contract.
     *
     * @return Collection<int, CommentMentionResourceData>
     */
    public function suggest(
        CommentMentionContext $context,
        string $query,
        int $limit,
    ): Collection {
        return collect([
            new CommentMentionResourceData(
                id: 'public-1',
                label: 'Public One',
                fields: ['kind' => 'public'],
                url: '/public-resources/public-1',
            ),
        ])->take($limit);
    }
}
