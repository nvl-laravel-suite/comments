<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Nvl\Comments\Services\CommentAttachments;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\NativeCommentRace;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Comments\Tests\NativeCommentMediaConcurrencyTestCase;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaLibrary;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Support\Config\PackageOptions;

uses(NativeCommentMediaConcurrencyTestCase::class);

/** Wait for a barrier file another race worker writes. */
function awaitCommentLockOrderBarrier(string $path): void
{
    $deadline = microtime(true) + 30;
    while (! is_file($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException("The comment lock-order barrier [{$path}] timed out.");
        }
        usleep(10_000);
        clearstatcache();
    }
}

it('holds the asset lock before the attachment takes a row lock while a deletion of the same asset waits for it', function (): void {
    config(['nvl-media.mutation_lock.wait_seconds' => 30]);
    $target = TestCommentTarget::create(['name' => 'Raced article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Attachment race'], $actor);
    $media = Media::factory()->create([
        'is_public' => false, 'status' => MediaLifecycleStatus::Available, 'mime_type' => 'image/jpeg',
        'uploaded_by' => (string) $actor->getAuthIdentifier(), 'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    $assetLockKey = (new ReflectionMethod(MediaMutationLock::class, 'lockKey'))->invoke(app(MediaMutationLock::class), $media->id);
    $directory = sys_get_temp_dir().'/nvl-comment-lock-order-'.bin2hex(random_bytes(6));
    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('The comment lock-order test could not allocate its barrier directory.');
    }
    $deleterName = basename($directory).'-delete';

    $attach = static function () use ($comment, $media, $actor, $assetLockKey, $directory, $deleterName): array {
        // At the first row lock: record whether the asset lock is taken, release the deletion, and wait until it is
        // retrying that asset lock before continuing.
        $assetLockHeld = null;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$assetLockHeld, $assetLockKey, $directory, $deleterName): void {
            if ($assetLockHeld !== null || ! str_contains(strtolower($sql), 'for update')) {
                return;
            }
            $probe = Cache::store(PackageOptions::lockStore('media', 'mutation'))->lock($assetLockKey, 5);
            $assetLockHeld = ! $probe->get();
            if (! $assetLockHeld) {
                $probe->release();
            }
            file_put_contents($directory.'/attach-row-lock', 'locked');
            $deadline = microtime(true) + 30;
            while (true) {
                foreach (Redis::connection('comments_concurrency')->client()->client('list') as $client) {
                    if (($client['name'] ?? null) === $deleterName && ($client['cmd'] ?? null) === 'set') {
                        return;
                    }
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('The deletion never attempted the asset lock.');
                }
                usleep(20_000);
            }
        });
        try {
            $failure = '';
            app(CommentAttachments::class)->attach($comment->id, $media->id, $actor);
        } catch (Throwable $exception) {
            $failure = $exception::class.': '.$exception->getMessage();
        }

        return ['asset_lock_held_at_first_row_lock' => $assetLockHeld === true, 'failure' => $failure];
    };
    $delete = static function () use ($media, $actor, $directory, $deleterName): array {
        awaitCommentLockOrderBarrier($directory.'/attach-row-lock');
        // Name this process's lock client so the attachment can see the deletion reach for the asset lock.
        Redis::connection('comments_concurrency')->client()->client('SETNAME', $deleterName);

        return ['deleted' => app(MediaLibrary::class)->delete($actor, $media->id)];
    };

    try {
        [$attached, $deleted] = NativeCommentRace::run([$attach, $delete]);
    } finally {
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }

    expect($attached)->toMatchArray(['ok' => true, 'asset_lock_held_at_first_row_lock' => true], 'The attachment took a row lock before the asset lock.')
        ->and($attached['failure'])->toBe('')
        ->and($deleted)->toMatchArray(['ok' => true, 'deleted' => true])
        ->and(Media::query()->find($media->id))->toBeNull();
});
