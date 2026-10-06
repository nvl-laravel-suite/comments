<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Count and bounded latest list from one identical authorized nondeleted set.
 *
 * @api
 */
#[TypeScript]
final class OwnerCommentSummaryData extends Data
{
    /**
     * Retain explicit empty latest lists for admitted owners without visible rows.
     *
     * @param  list<LatestCommentSummaryData>  $latest
     */
    public function __construct(
        public readonly int $visibleCount,
        #[DataCollectionOf(LatestCommentSummaryData::class)]
        public readonly array $latest,
    ) {}
}
