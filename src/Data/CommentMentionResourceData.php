<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use InvalidArgumentException;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;

/**
 * Authorized server-owned identity and label returned by a mention resolver.
 */
final class CommentMentionResourceData extends Data
{
    use DataTransform;

    /**
     * Create one resolved mention resource value.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
    ) {
        if (! mb_check_encoding($this->id, 'UTF-8')
            || preg_match('/\S/u', $this->id) !== 1
            || mb_strlen($this->id) > 255) {
            throw new InvalidArgumentException(
                'Comment mention resource identifiers must be bounded non-blank UTF-8 strings.',
            );
        }

        if (! mb_check_encoding($this->label, 'UTF-8')
            || preg_match('/\S/u', $this->label) !== 1
            || mb_strlen($this->label) > 255) {
            throw new InvalidArgumentException(
                'Comment mention resource labels must be bounded non-blank UTF-8 strings.',
            );
        }
    }
}
