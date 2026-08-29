<?php

declare(strict_types=1);

namespace Nvl\Comments\Actions;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentCreationWriter;

/**
 * Creates an idempotent root comment or bounded reply atomically.
 */
final readonly class CreateCommentAction
{
    /**
     * Create the plain comment creation action.
     */
    public function __construct(private CommentCreationWriter $writer) {}

    /**
     * Create a root comment or an authorized reply for a persisted target.
     */
    public function execute(
        Model $target,
        CreateCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment {
        return $this->writer->create($target, $data, $actor, $audience);
    }
}
