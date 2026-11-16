<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Database\Query\Builder;
use Nvl\Support\Contracts\TextMatcher;
use Nvl\Support\Integrations\DatabaseTextMatcher;

/**
 * Host matcher that applies its predicate to a clone and returns the clone, which the contract allows.
 */
final class CloningTextMatcher implements TextMatcher
{
    /** {@inheritDoc} */
    public function contains(Builder $query, string $column, string $value, bool $negated = false, bool $wildcards = false, string $boolean = 'and'): Builder
    {
        return app(DatabaseTextMatcher::class)->contains(clone $query, $column, $value, $negated, $wildcards, $boolean);
    }
}
