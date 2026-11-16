<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Nvl\Comments\Contracts\CommentAttachmentLifecycle;
use Nvl\Comments\Integrations\MediaCommentAttachmentLifecycle;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentAttachments;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Comments\Tests\ReverseMediaCommentsTestCase;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Services\DefaultMediaAuthorization;
use Nvl\Support\Enums\SystemActor;

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

it('reports malformed media and association identifiers as missing without querying a UUID column', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Attachment'], $actor);
    $bindings = [];
    DB::listen(function (QueryExecuted $query) use (&$bindings): void {
        $bindings = [...$bindings, ...$query->bindings];
    });

    expect(fn () => app(CommentAttachments::class)->attach($comment, 'not-a-uuid', $actor))->toThrow(ModelNotFoundException::class)
        ->and(app(CommentAttachments::class)->detach($comment, 'not-a-uuid', $actor))->toBeFalse()
        ->and($bindings)->not->toContain('not-a-uuid');
});

it('cleans up the attachments of replies removed with a force-deleted parent', function (): void {
    app()->bind(MediaAuthorization::class, fn (): MediaAuthorization => new class implements MediaAuthorization
    {
        /** Host policy admitting the background cleanup of a removed comment's attachments. */
        public function allows(Authenticatable|SystemActor $actor, MediaAbility $ability, ?Media $media = null, ?Model $owner = null): bool
        {
            return $actor === SystemActor::Background
                ? $owner instanceof Comment
                : (new DefaultMediaAuthorization)->allows($actor, $ability, $media, $owner);
        }
    });
    app()->forgetScopedInstances();
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    $parent = $writer->create($target, ['body' => 'Parent'], $actor);
    $reply = $writer->create($target, ['body' => 'Reply', 'parentId' => $parent->id], $actor);
    $nested = $writer->create($target, ['body' => 'Nested', 'parentId' => $reply->id], $actor);
    $media = collect([$reply, $nested])->map(function (Comment $comment) use ($actor): string {
        $media = Media::factory()->create([
            'is_public' => false, 'status' => MediaLifecycleStatus::Available, 'mime_type' => 'image/jpeg',
            'uploaded_by' => (string) $actor->getAuthIdentifier(), 'uploaded_by_type' => $actor->getMorphClass(),
        ]);
        app(CommentAttachments::class)->attach($comment, $media, $actor);

        return $media->id;
    })->all();
    expect(Media::query()->whereKey($media)->count())->toBe(2);

    $parent->forceDelete();

    expect(Comment::query()->withTrashed()->count())->toBe(0)
        ->and(Media::query()->whereKey($media)->count())->toBe(0);
});

it('force-deleting a trashed comment removes its attachments', function (bool $nested): void {
    app()->bind(MediaAuthorization::class, fn (): MediaAuthorization => new class implements MediaAuthorization
    {
        /** Host policy admitting the background cleanup of a removed comment's attachments. */
        public function allows(Authenticatable|SystemActor $actor, MediaAbility $ability, ?Media $media = null, ?Model $owner = null): bool
        {
            return $actor === SystemActor::Background
                ? $owner instanceof Comment
                : (new DefaultMediaAuthorization)->allows($actor, $ability, $media, $owner);
        }
    });
    app()->forgetScopedInstances();
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    $parent = $writer->create($target, ['body' => 'Parent'], $actor);
    $comment = $nested ? $writer->create($target, ['body' => 'Reply', 'parentId' => $parent->id], $actor) : $parent;
    $media = Media::factory()->create([
        'is_public' => false, 'status' => MediaLifecycleStatus::Available, 'mime_type' => 'image/jpeg',
        'uploaded_by' => (string) $actor->getAuthIdentifier(), 'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    app(CommentAttachments::class)->attach($comment, $media, $actor);
    $comment->delete();

    $nested ? $parent->fresh()->forceDelete() : Comment::query()->withTrashed()->findOrFail($comment->id)->forceDelete();

    expect(Comment::query()->withTrashed()->count())->toBe(0)
        ->and(Media::query()->find($media->id))->toBeNull();
})->with(['trashed comment' => false, 'trashed reply of a force-deleted parent' => true]);

it('descendant and parent veto preserve the entire tree and bytes', function (string $vetoed): void {
    Storage::fake('local');
    app()->bind(MediaAuthorization::class, fn (): MediaAuthorization => new class implements MediaAuthorization
    {
        /** Host policy admitting the background cleanup of a removed comment's attachments. */
        public function allows(Authenticatable|SystemActor $actor, MediaAbility $ability, ?Media $media = null, ?Model $owner = null): bool
        {
            return $actor === SystemActor::Background
                ? $owner instanceof Comment
                : (new DefaultMediaAuthorization)->allows($actor, $ability, $media, $owner);
        }
    });
    app()->forgetScopedInstances();
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    $parent = $writer->create($target, ['body' => 'Parent'], $actor);
    $reply = $writer->create($target, ['body' => 'Reply', 'parentId' => $parent->id], $actor);
    $nested = $writer->create($target, ['body' => 'Nested', 'parentId' => $reply->id], $actor);
    $comments = ['parent' => $parent, 'reply' => $reply, 'nested' => $nested];
    $paths = [];
    foreach ($comments as $comment) {
        $media = Media::factory()->create([
            'is_public' => false, 'status' => MediaLifecycleStatus::Available, 'mime_type' => 'image/jpeg',
            'uploaded_by' => (string) $actor->getAuthIdentifier(), 'uploaded_by_type' => $actor->getMorphClass(),
        ]);
        Storage::disk($media->disk)->put($media->buildPath(), 'bytes');
        $paths[$media->id] = [$media->disk, $media->buildPath()];
        app(CommentAttachments::class)->attach($comment, $media, $actor);
    }
    Event::listen('eloquent.deleting: '.Comment::class, static fn (Comment $comment): ?bool => $comment->id === $comments[$vetoed]->id ? false : null);

    expect($parent->forceDelete())->toBeFalse()
        ->and(Comment::query()->whereKey(array_map(static fn (Comment $comment): string => $comment->id, $comments))->count())->toBe(3)
        ->and(Media::query()->whereKey(array_keys($paths))->count())->toBe(3)
        ->and(MediaAssociation::query()->whereIn('media_id', array_keys($paths))->count())->toBe(3)
        ->and($parent->fresh()->reply_count)->toBe(1);
    foreach ($paths as [$disk, $path]) {
        expect(Storage::disk($disk)->exists($path))->toBeTrue();
    }
})->with(['nested', 'parent']);
