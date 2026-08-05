<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Stable actor identity independent of an application's user model.
 */
#[TypeScript]
final class CommentActorData extends Data
{
    use DataTransform;

    private const int MAXIMUM_TYPE_LENGTH = 100;

    private const int MAXIMUM_IDENTIFIER_LENGTH = 255;

    /**
     * Create one canonical anonymous, identified, or system actor identity.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly ?string $type,
        public readonly ?string $id,
        public readonly bool $system = false,
    ) {
        if ($this->system) {
            if ($this->type !== 'system' || $this->id !== null) {
                throw new InvalidArgumentException(
                    'System comment actors must use type [system] with a null identifier.',
                );
            }

            return;
        }

        if ($this->type === null && $this->id === null) {
            return;
        }

        if ($this->type === null || $this->id === null) {
            throw new InvalidArgumentException(
                'Comment actor type and identifier must both be null for anonymous actors or both be present for identified actors.',
            );
        }

        if ($this->type === 'system') {
            throw new InvalidArgumentException(
                'The [system] comment actor type is reserved for system actors.',
            );
        }

        self::assertIdentityPart('type', $this->type, self::MAXIMUM_TYPE_LENGTH);
        self::assertIdentityPart('identifier', $this->id, self::MAXIMUM_IDENTIFIER_LENGTH);
    }

    /**
     * Create the canonical anonymous actor identity.
     */
    public static function anonymous(): self
    {
        return new self(null, null);
    }

    /**
     * Create the canonical trusted system actor identity.
     */
    public static function system(): self
    {
        return new self('system', null, system: true);
    }

    /**
     * Create an identified comment actor from an authenticated principal.
     *
     * @throws InvalidArgumentException
     */
    public static function fromAuthenticatable(Authenticatable $actor): self
    {
        $identifier = $actor->getAuthIdentifier();

        if (! is_int($identifier) && ! is_string($identifier)) {
            throw new InvalidArgumentException(sprintf(
                'Authenticated comment actor [%s] must expose an integer or string identifier; [%s] returned.',
                $actor::class,
                get_debug_type($identifier),
            ));
        }

        return new self(
            type: $actor instanceof Model ? $actor->getMorphClass() : $actor::class,
            id: (string) $identifier,
        );
    }

    /**
     * Require one identity component to be bounded non-blank UTF-8 text.
     *
     * @throws InvalidArgumentException
     */
    private static function assertIdentityPart(
        string $name,
        string $value,
        int $maximumLength,
    ): void {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException(
                "Comment actor {$name} must be valid UTF-8.",
            );
        }

        if (preg_match('/\S/u', $value) !== 1) {
            throw new InvalidArgumentException(
                "Comment actor {$name} must not be blank.",
            );
        }

        if (mb_strlen($value, 'UTF-8') > $maximumLength) {
            throw new InvalidArgumentException(
                "Comment actor {$name} must not exceed {$maximumLength} characters.",
            );
        }
    }
}
