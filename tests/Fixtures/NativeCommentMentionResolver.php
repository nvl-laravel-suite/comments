<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Support\Collection;
use Nvl\Comments\Contracts\CommentMentionResourceResolver;
use Nvl\Comments\Enums\CommentMentionState;
use Nvl\Comments\ValueObjects\CommentMentionContext;

final class NativeCommentMentionResolver implements CommentMentionResourceResolver
{
    public function resolve(CommentMentionContext $context, array $ids): Collection
    {
        return collect($ids)->filter(static fn (string $id): bool => $id === 'member-1')
            ->map(static fn (string $id): array => [
                'id' => $id,
                'label' => 'Member One',
                'fields' => [],
                'url' => null,
                'state' => CommentMentionState::Resolved,
            ])->values();
    }

    public function suggest(CommentMentionContext $context, string $query, int $limit): Collection
    {
        return $this->resolve($context, ['member-1'])->take($limit)->values();
    }
}
