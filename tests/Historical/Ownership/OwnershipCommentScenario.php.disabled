<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Nvl\Comments\Actions\CreateCommentAction;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Models\Comment;
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;

/** Builds real admitted targets and comments through public workflows. */
final class OwnershipCommentScenario
{
    public static function target(): OwnershipCommentTarget
    {
        return app(OwnershipWriter::class)->create(OwnershipCommentTarget::class, static function (OwnershipAssignment $a): OwnershipCommentTarget {
            $target = new OwnershipCommentTarget;
            $target->forceFill([...$a->attributes(), 'name' => 'Target'])->save();

            return $target;
        });
    }

    public static function comment(?OwnershipCommentTarget $target = null): Comment
    {
        return app(CreateCommentAction::class)->execute($target ?? self::target(), CreateCommentData::from(['body' => 'Private comment']), self::actor());
    }

    public static function actor(): CommentActorData
    {
        return CommentActorData::fromAuthenticatable(OwnershipActor::firstOrFail());
    }
}
