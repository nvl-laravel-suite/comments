<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\CreateRichCommentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentCreationWriter;

/**
 * Creates one bounded rich comment and its current mention rows atomically.
 */
final readonly class CreateRichCommentAction
{
    /**
     * Create the rich comment creation action.
     */
    public function __construct(private CommentCreationWriter $writer) {}

    /**
     * Create an authorized rich root comment or reply for a persisted target.
     */
    public function execute(
        Model $target,
        CreateRichCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment {
        return $this->writer->createRich($target, $data, $actor, $audience);
    }
}
