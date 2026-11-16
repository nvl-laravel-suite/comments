<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentAttachments;
use Nvl\Comments\Services\CommentStateReconciler;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Comments\Tests\ReverseMediaCommentsTestCase;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Support\Enums\PublicActor;
use Nvl\Support\Enums\SystemActor;

uses(ReverseMediaCommentsTestCase::class);

it('legacy attachments remain readable, detachable and erasable', function (): void {
    $docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    config([
        'nvl-comments.attachments.enabled' => true,
        'nvl-comments.attachments.collection' => 'comment-attachments',
        'nvl-comments.attachments.mime_types' => ['image/jpeg', 'application/msword', $docx, 'application/zip'],
    ]);
    $policy = new class implements CommentAuthorization, CommentQueryScope
    {
        public function allows(CommentAbility $ability, Authenticatable|SystemActor|PublicActor $actor, ?Comment $comment = null, ?Model $target = null, CommentAudience $audience = CommentAudience::Public, array $context = []): bool
        {
            return true;
        }

        public function scopeComments(Builder $query, Authenticatable|SystemActor|PublicActor $actor, Model $target, CommentAudience $audience, CommentAbility $ability): void {}
    };
    app()->instance(CommentAuthorization::class, $policy);
    app()->instance(CommentQueryScope::class, $policy);
    app()->instance(MediaAuthorization::class, new class implements MediaAuthorization
    {
        public function allows(Authenticatable|SystemActor $actor, MediaAbility $ability, ?Media $media = null, ?Model $owner = null): bool
        {
            return true;
        }
    });
    $target = TestCommentTarget::create(['name' => 'Adopted article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    $legacy = static function (Comment $comment) use ($actor, $docx): MediaAssociation {
        $media = Media::factory()->create([
            'is_public' => false, 'status' => MediaLifecycleStatus::Available, 'mime_type' => $docx, 'size' => 1024,
            'uploaded_by' => (string) $actor->getAuthIdentifier(), 'uploaded_by_type' => $actor->getMorphClass(),
        ]);

        return MediaAssociation::query()->create([
            'id' => (string) Str::uuid(), 'media_id' => $media->id, 'associable_type' => $comment->getMorphClass(),
            'associable_id' => $comment->id, 'collection' => 'comment-attachments', 'order' => 0, 'is_active' => true,
        ]);
    };
    $detached = $writer->create($target, ['body' => 'Detach me'], $actor);
    $erased = $writer->create($target, ['body' => 'Erase me'], $actor);
    $first = $legacy($detached);
    $second = $legacy($erased);

    expect($detached->attachmentAssociations()->pluck('id')->all())->toBe([$first->id])
        ->and(app(CommentStateReconciler::class)->reconcile($target, 10))->invalidAttachmentAssociations->toBe(0)->healthy->toBeTrue();

    $zip = Media::factory()->create([
        'is_public' => false, 'status' => MediaLifecycleStatus::Available, 'mime_type' => 'application/zip', 'size' => 1024,
        'uploaded_by' => (string) $actor->getAuthIdentifier(), 'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    expect(app(CommentAttachments::class)->attach($erased, $zip, $actor))->collection->toBe('comment-attachments');

    expect(app(CommentAttachments::class)->detach($detached, $first->id, $actor))->toBeTrue()
        ->and($detached->attachmentAssociations()->exists())->toBeFalse();

    $writer->anonymize($erased, ['expected_revision' => $erased->fresh()->revision, 'reason' => 'Erasure request'], $actor);
    expect(MediaAssociation::query()->whereIn('media_id', [$second->media_id, $zip->id])->exists())->toBeFalse();
});
