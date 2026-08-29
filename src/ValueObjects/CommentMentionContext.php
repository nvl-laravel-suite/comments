<?php

declare(strict_types=1);

namespace Nvl\Comments\ValueObjects;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAudience;

/**
 * Carries the authorized target, actor, and audience into mention resolution.
 */
final readonly class CommentMentionContext
{
    /**
     * Create one mention-resolution context.
     */
    public function __construct(
        public Model $target,
        public CommentActorData $actor,
        public CommentAudience $audience,
    ) {}
}
