<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Comments\Contracts\CommentTargetResolver;
use Nvl\Comments\Exceptions\CommentTargetNotFoundException;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;

/**
 * Allowlist for API-visible comment target aliases.
 */
final class CommentTargetRegistry
{
    /** @var array<string, class-string<CommentTargetResolver>> */
    private array $resolvers = [];

    /** @var array<string, class-string<Model>> */
    private array $ownerModels = [];

    /** Create the package resolver registry with its identity and tenancy boundaries. */
    public function __construct(
        private readonly Container $container,
        private readonly TenantBoundary $boundary,
        private readonly TenantResourceRegistry $resources,
        private readonly Repository $config,
        private readonly OwnerRegistry $identities,
    ) {}

    /** Register one allowlisted resolver with an optional shared owner reference. */
    public function register(string $alias, string $resolver, ?string $owner = null): void
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

        if ($owner !== null) {
            $this->ownerModels[$alias] = $this->identities->model($owner);
        }

        $this->resolvers[$alias] = $resolver;
        ksort($this->resolvers);
    }

    /** Resolve one package-visible identity through its declared resolver. */
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
        $expectedModel = $this->ownerModels[$alias] ?? null;

        if ($expectedModel !== null && $target::class !== $expectedModel) {
            throw new InvalidArgumentException("Comments resolver [{$alias}] returned a different shared owner model.");
        }

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
