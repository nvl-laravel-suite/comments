<?php

declare(strict_types=1);

use Nvl\Comments\Contracts\CommentAttachmentProjection;
use Nvl\Comments\Integrations\MediaCommentAttachmentProjection;
use Nvl\Comments\Tests\ReverseMediaCommentsTestCase;

uses(ReverseMediaCommentsTestCase::class);

it('activates the loaded attachment adapter when Comments is registered before Media', function (): void {
    expect(app(CommentAttachmentProjection::class))->toBeInstanceOf(MediaCommentAttachmentProjection::class)
        ->and(app('router')->has('nvl.comments.attachments.asset'))->toBeTrue();
});
