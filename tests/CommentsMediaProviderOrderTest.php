<?php

declare(strict_types=1);

use Nvl\Comments\Contracts\CommentAttachmentLifecycle;
use Nvl\Comments\Integrations\MediaCommentAttachmentLifecycle;
use Nvl\Comments\Services\CommentAttachments;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Comments\Tests\ReverseMediaCommentsTestCase;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Models\Media;

uses(ReverseMediaCommentsTestCase::class);

it('activates native attachments when Media registers after Comments', function (): void {
    expect(app(CommentAttachmentLifecycle::class))->toBeInstanceOf(MediaCommentAttachmentLifecycle::class);
    expect(app(CommentAttachments::class))->toBeInstanceOf(CommentAttachments::class);
});

it('attaches and detaches private media for the persisted comment author', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Attachment'], $actor);
    $media = Media::factory()->create([
        'is_public' => false,
        'status' => MediaLifecycleStatus::Available,
        'mime_type' => 'image/jpeg',
        'uploaded_by' => (string) $actor->getAuthIdentifier(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);

    $association = app(CommentAttachments::class)->attach($comment, $media, $actor);

    expect($comment->attachmentAssociations()->whereKey($association->id)->exists())->toBeTrue();
    expect(app(CommentAttachments::class)->detach($comment, $association->id, $actor))->toBeTrue();
    expect($comment->attachmentAssociations()->whereKey($association->id)->where('is_active', true)->exists())->toBeFalse();
});
