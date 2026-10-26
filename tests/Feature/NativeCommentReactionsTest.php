<?php

declare(strict_types=1);

use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Services\CommentReactions;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;

it('adds and removes one reaction idempotently for an explicit actor', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $author = NativeCommentActor::create(['name' => 'Author']);
    $reactor = NativeCommentActor::create(['name' => 'Reactor']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'React'], $author, CommentAudience::Public);
    $reactions = app(CommentReactions::class);

    $first = $reactions->add($comment, 'like', $reactor);
    $again = $reactions->add($comment, 'like', $reactor);

    expect($again->id)->toBe($first->id);
    expect($comment->fresh()?->reaction_count)->toBe(1);

    $reactions->remove($comment, 'like', $reactor);
    $reactions->remove($comment, 'like', $reactor);
    expect($comment->fresh()?->reaction_count)->toBe(0);
});
