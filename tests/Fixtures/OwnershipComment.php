<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Nvl\Comments\Models\Comment;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

class OwnershipComment extends Comment
{
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::inherited('commentable');
    }
}
