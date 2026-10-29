<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Auth\CanonicalActorResolver;
use Nvl\Support\Enums\PublicActor;
use Nvl\Support\Enums\SystemActor;

/**
 * Canonical identity and original principal for one comment operation.
 *
 * @internal
 */
final readonly class CommentActor
{
    private function __construct(
        public Authenticatable|SystemActor|PublicActor $principal,
        public ?string $type,
        public ?string $id,
        public bool $system,
    ) {}

    public static function from(Authenticatable|SystemActor|PublicActor $principal): self
    {
        $identity = self::inspect($principal);

        if (! $principal instanceof Authenticatable || ! $principal instanceof Model) {
            return $identity;
        }

        $current = app(CanonicalActorResolver::class)->resolve($principal);

        return self::inspect($current);
    }

    /** Inspect a canonical principal already reloaded by the public service. */
    public static function inspect(Authenticatable|SystemActor|PublicActor $principal): self
    {
        if ($principal === PublicActor::Anonymous) {
            return new self($principal, null, null, false);
        }

        if ($principal === SystemActor::Background) {
            return new self($principal, 'system', null, true);
        }

        if (! $principal instanceof Model || ! $principal->exists || $principal->getKey() === null) {
            throw new AuthorizationException('A persisted comment actor is required.');
        }

        $prototype = new ($principal::class);
        $keyName = $prototype->getKeyName();
        $declaredConnection = $principal->getConnectionName();
        if ($principal->getTable() !== $prototype->getTable()
            || ($declaredConnection !== null && $declaredConnection !== $prototype->getConnection()->getName())
            || $principal->getConnection() !== $prototype->getConnection()
            || $principal->getKeyName() !== $keyName
            || $principal->isDirty($keyName)
            || $principal->getRawOriginal($keyName) !== $principal->getKey()) {
            throw new AuthorizationException('The comment actor identity is not canonical.');
        }

        $identifier = $principal->getAuthIdentifier();
        if (! is_int($identifier) && ! is_string($identifier)) {
            throw new AuthorizationException('The comment actor identifier is invalid.');
        }

        $type = $principal->getMorphClass();
        $id = (string) $identifier;
        if (! mb_check_encoding($type, 'UTF-8') || ! mb_check_encoding($id, 'UTF-8')
            || preg_match('/\S/u', $type) !== 1 || preg_match('/\S/u', $id) !== 1
            || mb_strlen($type) > 100 || mb_strlen($id) > 255 || $type === 'system') {
            throw new AuthorizationException('The comment actor identity is invalid.');
        }

        return new self($principal, $type, $id, false);
    }

    public static function anonymous(): self
    {
        return self::from(PublicActor::Anonymous);
    }

    public static function fromAuthenticatable(Authenticatable $principal): self
    {
        return self::from($principal);
    }
}
