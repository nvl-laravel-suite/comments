<?php

declare(strict_types=1);

use Nvl\Comments\Actions\AttachCommentMediaAction;
use Nvl\Comments\Actions\CreateCommentAction;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Services\CommentProjectionFactory;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;

it('automatically enables loaded Media while preserving attachment privacy and soft deletion', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Media discussion']);
    $comment = app(CreateCommentAction::class)->execute($target, new CreateCommentData('Attachment owner'), CommentActorData::system());
    $media = Media::factory()->create(['is_public' => false, 'visibility' => MediaVisibility::Private, 'status' => MediaLifecycleStatus::Available, 'mime_type' => 'image/jpeg']);
    $association = app(AttachCommentMediaAction::class)->execute($comment, $media, CommentActorData::system());

    expect(config('nvl-comments.attachments.enabled'))->toBeNull()
        ->and(app('router')->has('nvl.comments.attachments.asset'))->toBeTrue()
        ->and($comment->attachmentAssociations()->count())->toBe(1)
        ->and(app(CommentProjectionFactory::class)->publicComment($comment, $target)->attachmentCount)->toBe(0);

    $comment->delete();
    expect(MediaAssociation::query()->whereKey($association->id)->exists())->toBeTrue();
    $comment->forceDelete();
    expect(Media::query()->whereKey($media->id)->exists())->toBeFalse()
        ->and(Media::query()->withTrashed()->whereKey($media->id)->exists())->toBeTrue();
});
