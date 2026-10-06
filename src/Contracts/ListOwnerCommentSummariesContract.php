<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\OwnerCommentSummaries;
use Nvl\Comments\Enums\CommentAudience;

/**
 * Returns bounded visible summaries for canonically admitted persisted owners.
 *
 * @api
 */
interface ListOwnerCommentSummariesContract
{
    /**
     * Read at most 100 owners and one to ten latest comments for each owner.
     *
     * @param  list<Model>  $owners
     */
    public function execute(array $owners, CommentActorData $actor, CommentAudience $audience = CommentAudience::Public, int $latestLimit = 3): OwnerCommentSummaries;
}
