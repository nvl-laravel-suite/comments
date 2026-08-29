<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Comments\Contracts\CommentMentionUrlResolver;
use Nvl\Comments\ValueObjects\CommentMentionContext;

/**
 * Produces package-owned test URLs from authorized mention resources.
 */
final class TestCommentMentionUrlResolver implements CommentMentionUrlResolver
{
    /**
     * Resolve one deterministic URL without accepting client route data.
     */
    public function resolve(Model $resource, CommentMentionContext $context): string
    {
        $identifier = $resource->getKey();

        if (! is_string($identifier) && ! is_int($identifier)) {
            throw new InvalidArgumentException('Test mention resource identifiers are invalid.');
        }

        return '/organizations/'.rawurlencode((string) $identifier);
    }
}
