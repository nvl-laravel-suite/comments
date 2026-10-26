<?php

declare(strict_types=1);

use Nvl\Comments\Services\CommentAttachments;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;

it('fails explicitly when attachments are requested without the Media provider', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Attachment'], $actor);

    app(CommentAttachments::class)->attach($comment, 'missing-media', $actor);
})->throws(InvalidArgumentException::class);
