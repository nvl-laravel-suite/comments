<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\ValueObjects\CommentMentionContext;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Narrows an intentionally global mention catalog to principals visible in one tenant. */
interface CommentMentionTenantProjection
{
    /** @param Builder<Model> $query */
    public function scope(Builder $query, CommentMentionContext $context, TenantId $tenant): void;
}
