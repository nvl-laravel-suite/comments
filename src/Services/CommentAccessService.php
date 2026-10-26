<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;

/**
 * Evaluates comment authorization and standardizes denial behavior.
 *
 * @internal
 */
final readonly class CommentAccessService
{
    public function __construct(
        private CommentAuthorization $authorization,
    ) {}

    /**
     * Determine whether an actor may perform a comment ability.
     *
     * @param  array<string, mixed>  $context
     */
    public function allows(
        CommentAbility $ability,
        CommentActor $actor,
        ?Comment $comment = null,
        ?Model $target = null,
        CommentAudience $audience = CommentAudience::Public,
        array $context = [],
    ): bool {
        return $this->authorization->allows(
            $ability,
            $actor->principal,
            $comment,
            $target,
            $audience,
            $context,
        );
    }

    /**
     * Authorize an ability and optionally conceal denials as missing resources.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws AuthorizationException
     */
    public function authorize(
        CommentAbility $ability,
        CommentActor $actor,
        ?Comment $comment = null,
        ?Model $target = null,
        CommentAudience $audience = CommentAudience::Public,
        array $context = [],
        bool $asNotFound = false,
    ): void {
        if ($this->allows(
            $ability,
            $actor,
            $comment,
            $target,
            $audience,
            $context,
        )) {
            return;
        }

        $exception = new AuthorizationException(
            "Comment ability [{$ability->value}] is not authorized.",
        );

        throw $asNotFound ? $exception->asNotFound() : $exception;
    }
}
