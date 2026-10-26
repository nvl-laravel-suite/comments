<?php

declare(strict_types=1);

namespace Nvl\Comments\ValueObjects;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Services\CommentActor;

/**
 * Carries the authorized target, actor, and audience into mention resolution.
 *
 * @api
 */
final readonly class CommentMentionContext
{
    /**
     * Create one mention-resolution context.
     */
    public function __construct(
        public Model $target,
        public CommentActor $actor,
        public CommentAudience $audience,
    ) {}
}
