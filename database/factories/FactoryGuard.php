<?php

declare(strict_types=1);

namespace Nvl\Comments\Database\Factories;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Contracts\TenantDirectory;
use Nvl\Support\Tenancy\Contracts\TenantParentResolver;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Enums\TenantResourceKind;
use Nvl\Support\Tenancy\Enums\TenantStatus;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/**
 * Checks fixture identities against native persistence and tenant admission.
 *
 * @internal
 */
final class FactoryGuard
{
    /** Require a scalar native identifier before lookup or morph serialization. */
    public static function identifier(mixed $key): int|string
    {
        if ((! is_string($key) && ! is_int($key)) || $key === '') {
            throw new InvalidArgumentException('Factory identities require a non-empty native scalar identifier.');
        }

        return $key;
    }

    /** Require a persisted parent on the fixture's effective connection. */
    public static function parent(Model $parent, Model $child): void
    {
        $key = $parent->getKey();
        if (! $parent->exists || (! is_string($key) && ! is_int($key))
            || $key === '' || $parent->getRawOriginal($parent->getKeyName()) !== $key) {
            throw new InvalidArgumentException('Factory parents require an unchanged persisted native identifier.');
        }
        if ($parent->getConnection() !== $child->getConnection()) {
            throw new InvalidArgumentException('Factory parents and children require the same effective connection.');
        }
        if (config('nvl-tenancy.enabled') === true) {
            $container = Container::getInstance();
            $resource = $container->make(TenantResourceRegistry::class)->forModel($parent);
            $container->make(TenantBoundary::class)->assertRecord($parent, $resource->key);
        }
    }

    /** Validate a required native host morph identity before child persistence. */
    public static function owner(Model $child, string $typeColumn, string $idColumn): Model
    {
        $type = $child->getAttribute($typeColumn);
        $key = $child->getAttribute($idColumn);
        if (! is_string($type) || $type === '' || (! is_string($key) && ! is_int($key)) || $key === '') {
            throw new InvalidArgumentException('This factory requires forOwner() with a persisted real host model.');
        }
        $class = Relation::getMorphedModel($type) ?? $type;
        if (! is_a($class, Model::class, true) || $class === Model::class) {
            throw new InvalidArgumentException('Factory ownership requires a concrete native morph model.');
        }
        $prototype = new $class;
        if ($prototype->getConnection() !== $child->getConnection()) {
            throw new InvalidArgumentException('Factory owners require the fixture connection.');
        }
        $owner = $prototype->newQuery()->findOrFail($key);
        self::parent($owner, $child);
        if ($owner->getMorphClass() !== $type) {
            throw new InvalidArgumentException('Factory owners require their native morph identity.');
        }

        if (config('nvl-tenancy.enabled') === true) {
            $container = Container::getInstance();
            $registry = $container->make(TenantResourceRegistry::class);
            $resource = $registry->forModel($child);
            if ($resource->kind === TenantResourceKind::Inherited && $resource->parentResource === null) {
                $resolver = $container->make($registry->parentResolver($resource->key));
                if (! $resolver instanceof TenantParentResolver
                    || ($resolver->types()[$type] ?? null) !== $owner::class) {
                    throw new TenantBoundaryViolation('Factory owners require the native tenant parent allowlist.');
                }
            }
        }

        return $owner;
    }

    /** Apply admitted root ownership without activating or adopting Tenancy. */
    public static function root(Model $model, string $resource): void
    {
        if (config('nvl-tenancy.enabled') === true) {
            $model->forceFill(Container::getInstance()->make(TenantBoundary::class)->attributes($resource));
        }
    }

    /** Copy persisted parent ownership after native admission. */
    public static function inherit(Model $model, Model $parent, bool $withOwnershipKey = false): void
    {
        self::parent($parent, $model);
        if (config('nvl-tenancy.enabled') !== true) {
            return;
        }
        $attributes = [];
        if (array_key_exists('tenant_id', $parent->getAttributes())) {
            $attributes['tenant_id'] = $parent->getRawOriginal('tenant_id');
        }
        if ($withOwnershipKey && array_key_exists('ownership_key', $parent->getAttributes())) {
            $attributes['ownership_key'] = $parent->getRawOriginal('ownership_key');
        }
        $model->forceFill($attributes);
    }

    /** Require an active catalog recipient and explicit platform context. */
    public static function recipient(TenantId $recipient): void
    {
        $container = Container::getInstance();
        if ($container->make(TenantContext::class)->snapshot()->mode !== TenantContextMode::Platform
            || $container->make(TenantDirectory::class)->find($recipient)->status !== TenantStatus::Active) {
            throw new TenantBoundaryViolation('Catalog fixtures require platform context and an active recipient.');
        }
    }

    private function __construct() {}
}
