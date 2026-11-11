<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Declarative mention resource whose host table has not been migrated yet.
 */
final class UnmigratedCommentMentionResource extends Model
{
    /** @var string */
    protected $table = 'comment_mention_unmigrated_resources';
}
