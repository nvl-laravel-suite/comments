<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use Nvl\Support\Owners\OwnerResultMap;
use Spatie\LaravelData\Data;
use stdClass;

/**
 * Object-valued native morph/key maps with stable requested identity order.
 *
 * @api
 */
final class OwnerCommentSummaries extends Data
{
    /**
     * Retain exact string identities, including numeric keys.
     *
     * @param  list<array{type: string, id: string}>  $order
     */
    public function __construct(
        public readonly stdClass $owners,
        public readonly array $order,
    ) {}

    /**
     * Build the public map from complete admitted results.
     *
     * @param  OwnerResultMap<OwnerCommentSummaryData>  $map
     */
    public static function fromMap(OwnerResultMap $map): self
    {
        return new self($map->jsonSerialize(), $map->order());
    }
}
