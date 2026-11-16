<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Nvl\Comments\Services\CommentAttachments;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\NativeCommentRace;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Comments\Tests\NativeCommentMediaConcurrencyTestCase;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaLibrary;

uses(NativeCommentMediaConcurrencyTestCase::class);

it('finishes a comment attachment racing a deletion of the same asset without a lock wait', function (): void {
    $target = TestCommentTarget::create(['name' => 'Raced article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Attachment race'], $actor);
    $media = Media::factory()->create([
        'is_public' => false, 'status' => MediaLifecycleStatus::Available, 'mime_type' => 'image/jpeg',
        'uploaded_by' => (string) $actor->getAuthIdentifier(), 'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    $mediaTable = $media->getTable();
    $locksMedia = static fn (string $sql): bool => str_contains($sql, $mediaTable) && str_contains(strtolower($sql), 'for update');

    $attach = static function () use ($comment, $media, $actor, $locksMedia): array {
        // Hold the asset row long enough for the deletion to take its asset lock first.
        $slept = false;
        DB::listen(static function (QueryExecuted $query) use (&$slept, $locksMedia): void {
            if (! $slept && $locksMedia($query->sql)) {
                $slept = true;
                usleep(1_000_000);
            }
        });

        return ['association' => app(CommentAttachments::class)->attach($comment->id, $media->id, $actor)->id];
    };
    $delete = static function () use ($media, $actor, $locksMedia): array {
        // Take the asset lock, then reach for the row while the attachment may hold it.
        $slept = false;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$slept, $locksMedia): void {
            if (! $slept && $locksMedia($sql)) {
                $slept = true;
                usleep(500_000);
            }
        });

        return ['deleted' => app(MediaLibrary::class)->delete($actor, $media->id)];
    };

    [$attached, $deleted] = NativeCommentRace::run([$attach, $delete]);

    expect($deleted)->toMatchArray(['ok' => true, 'deleted' => true])
        ->and($attached['ok'] === true ? null : $attached['exception'].': '.$attached['message'])->toBeIn([null, ModelNotFoundException::class.': No query results for model ['.Media::class.'] '.$media->id])
        ->and(Media::query()->find($media->id))->toBeNull();
});
