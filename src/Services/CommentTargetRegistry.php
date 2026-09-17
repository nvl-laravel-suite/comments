<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Comments\Contracts\CommentTargetResolver;
use Nvl\Comments\Exceptions\CommentTargetNotFoundException;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantResourceRegistry;

/**
 * Allowlist for API-visible comment target aliases.
 */
final class CommentTargetRegistry
{
    /** @var array<string, class-string<CommentTargetResolver>> */
    private array $resolvers = [];

    public function __construct(
        private readonly Container $container,
        private readonly TenantBoundary $boundary,
        private readonly TenantResourceRegistry $resources,
        private readonly Repository $config,
    ) {}

    public function register(string $alias, string $resolver): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $alias) !== 1) {
            throw new InvalidArgumentException("Comment target alias [{$alias}] is invalid.");
        }

        if (isset($this->resolvers[$alias])) {
            throw new InvalidArgumentException("Comment target [{$alias}] is already registered.");
        }

        if (! is_a($resolver, CommentTargetResolver::class, true)) {
            throw new InvalidArgumentException(
                "Comment target resolver [{$resolver}] must implement CommentTargetResolver.",
            );
        }

        $this->resolvers[$alias] = $resolver;
        ksort($this->resolvers);
    }

    public function resolve(string $alias, string $identifier): Model
    {
        $class = $this->resolvers[$alias]
            ?? throw CommentTargetNotFoundException::forAlias($alias);
        $resolver = $this->container->make($class);

        if (! $resolver instanceof CommentTargetResolver || $resolver->alias() !== $alias) {
            throw new InvalidArgumentException("Comment target resolver [{$class}] is invalid.");
        }

        $target = $resolver->resolve($identifier)
            ?? throw CommentTargetNotFoundException::forIdentifier($alias, $identifier);
        if ($this->config->get('tenancy.enabled') === true) {
            $this->boundary->assertRecord($target, $this->resources->forModel($target)->key);
        }

        return $target;
    }

    /**
     * @return list<string>
     */
    public function aliases(): array
    {
        return array_keys($this->resolvers);
    }
}
