<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Contracts\ListOwnerCommentSummariesContract;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\OwnerCommentSummaries;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Services\CommentBatchReader;

/**
 * Public DTO read boundary for finite many-owner comment summaries.
 *
 * @api
 */
final readonly class ListOwnerCommentSummariesAction implements ListOwnerCommentSummariesContract
{
    /** Retain the scoped batch reader. */
    public function __construct(private CommentBatchReader $reader) {}

    /**
     * Return count and lightweight latest projections for admitted owners.
     *
     * @param  list<Model>  $owners
     */
    public function execute(array $owners, CommentActorData $actor, CommentAudience $audience = CommentAudience::Public, int $latestLimit = 3): OwnerCommentSummaries
    {
        return $this->reader->read($owners, $actor, $audience, $latestLimit);
    }
}
