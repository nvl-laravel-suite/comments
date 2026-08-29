<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\ValueObjects\CommentMentionContext;

/**
 * Produces server-owned URLs for already-authorized mention resources.
 */
interface CommentMentionUrlResolver
{
    /**
     * Resolve one optional package projection URL.
     */
    public function resolve(Model $resource, CommentMentionContext $context): ?string;
}
