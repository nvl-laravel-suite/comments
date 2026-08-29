<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Nvl\Comments\Contracts\CommentMentionResourceResolver;
use Nvl\Comments\Data\CommentMentionResourceData;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Comments\ValueObjects\CommentMentionContext;

/**
 * Routes bounded mention batches to explicitly registered resource resolvers.
 */
final class CommentMentionResourceRegistry
{
    /** @var array<string, class-string<CommentMentionResourceResolver>> */
    private array $resolvers = [];

    /**
     * Create the mention resource alias registry.
     */
    public function __construct(private readonly Container $container) {}

    /**
     * Register one stable resource alias and container-resolvable resolver.
     */
    public function register(string $alias, string $resolver): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $alias) !== 1) {
            throw new InvalidArgumentException('Comment mention resource aliases are invalid.');
        }

        if (isset($this->resolvers[$alias])) {
            throw new InvalidArgumentException('Comment mention resource aliases must be unique.');
        }

        if (! is_a($resolver, CommentMentionResourceResolver::class, true)) {
            throw new InvalidArgumentException(
                'Comment mention resource resolvers must implement CommentMentionResourceResolver.',
            );
        }

        $this->resolvers[$alias] = $resolver;
        ksort($this->resolvers);
    }

    /**
     * Resolve one bounded unique identifier batch for a registered resource alias.
     *
     * @param  list<string>  $ids
     * @return Collection<int, CommentMentionResourceData>
     */
    public function resolve(
        string $alias,
        CommentMentionContext $context,
        array $ids,
    ): Collection {
        $resolverClass = $this->resolvers[$alias] ?? null;

        if ($resolverClass === null) {
            throw new InvalidCommentMutationException(
                'Comment document contains an unregistered mention resource.',
            );
        }

        $ids = array_values(array_unique($ids));
        $maximum = min(100, CommentsConfiguration::positiveInteger(
            'comments.mentions.maximum_batch_size',
            100,
        ));

        if ($ids === [] || count($ids) > $maximum) {
            throw new InvalidCommentMutationException(
                'Comment mention resolution batch is outside the configured bounds.',
            );
        }

        $resolver = $this->container->make($resolverClass);

        if (! $resolver instanceof CommentMentionResourceResolver) {
            throw new InvalidArgumentException(
                'The configured comment mention resource resolver is invalid.',
            );
        }

        $resolved = $resolver->resolve($context, $ids);
        $byId = [];

        foreach ($resolved as $resource) {
            if (! in_array($resource->id, $ids, true)
                || isset($byId[$resource->id])) {
                throw new InvalidCommentMutationException(
                    'Comment mention resolution returned an invalid resource batch.',
                );
            }

            $byId[$resource->id] = $resource;
        }

        if (count($byId) !== count($ids)) {
            throw new InvalidCommentMutationException(
                'Comment document contains an unavailable or unauthorized mention resource.',
            );
        }

        return collect($ids)->map(
            static fn (string $id): CommentMentionResourceData => $byId[$id],
        );
    }

    /**
     * Return registered aliases in deterministic order.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        return array_keys($this->resolvers);
    }
}
