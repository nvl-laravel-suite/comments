<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Comments\Actions\AnonymizeCommentAction;
use Nvl\Comments\Actions\AttachCommentMediaAction;
use Nvl\Comments\Actions\CreateCommentAction;
use Nvl\Comments\Actions\DeleteCommentAction;
use Nvl\Comments\Actions\ReportCommentAction;
use Nvl\Comments\Actions\RestoreCommentAction;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\AnonymizeCommentData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Data\Mutations\DeleteCommentData;
use Nvl\Comments\Data\Mutations\ReportCommentData;
use Nvl\Comments\Data\Mutations\RestoreCommentData;
use Nvl\Comments\Exceptions\CommentIdempotencyConflictException;
use Nvl\Comments\Exceptions\CommentMutationBusyException;
use Nvl\Comments\Exceptions\InvalidCommentLifecycleException;
use Nvl\Comments\Exceptions\StaleCommentException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentStateReconciler;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Comments\Tests\Fixtures\ConcurrentCommentTarget;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;

/**
 * Return why process-level database races cannot run in this environment.
 */
function commentsConcurrencySkipReason(): ?string
{
    $driver = DB::connection()->getDriverName();

    if (! in_array($driver, ['mysql', 'pgsql'], true)) {
        return 'The process concurrency gates run in the PostgreSQL/MySQL matrix.';
    }

    if (! function_exists('pcntl_exec')
        || ! function_exists('pcntl_fork')
        || ! function_exists('pcntl_waitpid')) {
        return 'The process concurrency gates require pcntl.';
    }

    return null;
}

/**
 * Configure an autocommit connection used to publish and inspect race fixtures.
 */
function commentsConcurrencyPrepareConnection(): string
{
    $connectionName = config('database.default');

    if (! is_string($connectionName) || $connectionName === '') {
        throw new RuntimeException('The concurrency database connection is unavailable.');
    }

    $connectionConfig = config("database.connections.{$connectionName}");

    if (! is_array($connectionConfig)) {
        throw new RuntimeException('The concurrency database configuration is unavailable.');
    }

    config()->set(
        'database.connections.comments_concurrency_target',
        $connectionConfig,
    );
    config()->set('comments.mutation_lock.enabled', true);
    config()->set('media.mutation_lock.enabled', true);
    DB::purge('comments_concurrency_target');

    return $connectionName;
}

/**
 * Wait until every child process has published its ready marker.
 *
 * @param  list<string>  $paths
 */
function commentsConcurrencyWaitForFiles(array $paths): void
{
    $deadline = microtime(true) + 10;

    do {
        $ready = true;

        foreach ($paths as $path) {
            if (file_get_contents($path) !== 'ready') {
                $ready = false;

                break;
            }
        }

        if ($ready) {
            return;
        }

        usleep(10_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('Concurrent comment workers did not become ready.');
}

/**
 * Run bounded callbacks in separate PHP processes against fresh connections.
 *
 * @param  list<Closure(): array<string, mixed>>  $workers
 * @return list<array<string, mixed>>
 */
function commentsConcurrencyRunWorkers(
    string $connectionName,
    array $workers,
): array {
    $connectionConfig = config("database.connections.{$connectionName}");

    if (! is_array($connectionConfig)) {
        throw new RuntimeException('The concurrency worker database configuration is unavailable.');
    }

    $gate = tempnam(sys_get_temp_dir(), 'comments-gate-');
    $readyFiles = [];
    $resultFiles = [];

    foreach ($workers as $_worker) {
        $readyFiles[] = tempnam(sys_get_temp_dir(), 'comments-ready-');
        $resultFiles[] = tempnam(sys_get_temp_dir(), 'comments-result-');
    }

    if (! is_string($gate)
        || in_array(false, $readyFiles, true)
        || in_array(false, $resultFiles, true)) {
        throw new RuntimeException('The concurrency test could not allocate IPC files.');
    }

    /** @var list<non-falsy-string> $readyFiles */
    /** @var list<non-falsy-string> $resultFiles */
    $children = [];

    try {
        foreach ($workers as $workerIndex => $worker) {
            $workerConnectionName = "comments_concurrency_worker_{$workerIndex}";
            $processId = pcntl_fork();

            if ($processId === -1) {
                throw new RuntimeException('The concurrency test could not fork a worker.');
            }

            if ($processId > 0) {
                $children[] = $processId;

                continue;
            }

            try {
                config()->set(
                    "database.connections.{$workerConnectionName}",
                    $connectionConfig,
                );
                config()->set('database.default', $workerConnectionName);
                file_put_contents($readyFiles[$workerIndex], 'ready');
                $deadline = microtime(true) + 10;

                while (file_get_contents($gate) !== 'go') {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('The concurrency gate timed out.');
                    }

                    usleep(10_000);
                }

                $result = ['ok' => true, ...$worker()];
            } catch (Throwable $exception) {
                $result = [
                    'ok' => false,
                    'error' => $exception::class,
                    'message' => $exception->getMessage(),
                ];
            }

            file_put_contents(
                $resultFiles[$workerIndex],
                json_encode($result, JSON_THROW_ON_ERROR),
            );

            if (pcntl_exec('/usr/bin/true') === false) {
                exit(1);
            }
        }

        commentsConcurrencyWaitForFiles($readyFiles);
        file_put_contents($gate, 'go');

        foreach ($children as $child) {
            $status = 0;
            pcntl_waitpid($child, $status);

            if (! is_int($status)) {
                throw new RuntimeException(
                    'A concurrency worker returned an invalid process status.',
                );
            }

            expect(pcntl_wifexited($status))->toBeTrue()
                ->and(pcntl_wexitstatus($status))->toBe(0);
        }

        /** @var list<array<string, mixed>> $results */
        $results = array_map(
            static function (string $path): array {
                $decoded = json_decode(
                    (string) file_get_contents($path),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );

                if (! is_array($decoded)) {
                    throw new RuntimeException(
                        'A concurrency worker returned invalid data.',
                    );
                }

                $result = [];

                foreach ($decoded as $key => $value) {
                    if (! is_string($key)) {
                        throw new RuntimeException(
                            'A concurrency worker returned an invalid result key.',
                        );
                    }

                    $result[$key] = $value;
                }

                return $result;
            },
            $resultFiles,
        );

        return $results;
    } finally {
        foreach ([$gate, ...$readyFiles, ...$resultFiles] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

/**
 * Insert the model used as a canonical target on the committed test connection.
 */
function commentsConcurrencyInsertTarget(string $targetId): void
{
    $now = now();

    DB::connection('comments_concurrency_target')
        ->table(CommentsConfiguration::table('comments'))
        ->insert([
            'id' => $targetId,
            'commentable_type' => 'concurrency-fixture',
            'commentable_id' => $targetId,
            'commentable_identity_hash' => CommentIdentity::pair(
                'concurrency-fixture',
                $targetId,
            ),
            'body' => 'Concurrency target holder',
            'format' => 'plain',
            'status' => 'approved',
            'status_hash' => CommentIdentity::value('comment-status', 'approved'),
            'visibility' => 'public',
            'visibility_hash' => CommentIdentity::value('comment-visibility', 'public'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
}

/**
 * Insert one comment fixture without involving the parent test transaction.
 *
 * @param  array<string, mixed>  $overrides
 */
function commentsConcurrencyInsertComment(
    string $commentId,
    string $targetId,
    array $overrides = [],
): void {
    $now = now();

    DB::connection('comments_concurrency_target')
        ->table(CommentsConfiguration::table('comments'))
        ->insert(array_replace([
            'id' => $commentId,
            'commentable_type' => ConcurrentCommentTarget::class,
            'commentable_id' => $targetId,
            'commentable_identity_hash' => CommentIdentity::pair(
                ConcurrentCommentTarget::class,
                $targetId,
            ),
            'actor_type' => 'member',
            'actor_id' => 'concurrent-author',
            'actor_identity_hash' => CommentIdentity::pair(
                'member',
                'concurrent-author',
            ),
            'body' => 'Concurrent comment',
            'format' => 'plain',
            'status' => 'approved',
            'status_hash' => CommentIdentity::value('comment-status', 'approved'),
            'visibility' => 'public',
            'visibility_hash' => CommentIdentity::value('comment-visibility', 'public'),
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
}

/**
 * Insert one private, available PDF for attachment races.
 */
function commentsConcurrencyInsertMedia(string $mediaId): void
{
    $now = now();

    DB::connection('comments_concurrency_target')
        ->table((new Media)->getTable())
        ->insert([
            'id' => $mediaId,
            'filename' => 'concurrency.pdf',
            'hash' => hash('sha256', $mediaId).'.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'disk' => 'local',
            'folder' => 'concurrency',
            'is_public' => false,
            'visibility' => 'private',
            'status' => 'available',
            'revision' => 1,
            'type' => 'document',
            'digest' => hash('sha256', "digest:{$mediaId}"),
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
}

/**
 * Remove every committed fixture belonging to one concurrency target.
 *
 * @param  list<string>  $mediaIds
 */
function commentsConcurrencyCleanup(
    string $targetId,
    array $mediaIds = [],
): void {
    $connection = DB::connection('comments_concurrency_target');
    $commentTable = CommentsConfiguration::table('comments');
    $commentIds = $connection
        ->table($commentTable)
        ->where('commentable_type', ConcurrentCommentTarget::class)
        ->where('commentable_id', $targetId)
        ->orderByDesc('depth')
        ->pluck('id')
        ->filter(static fn (mixed $id): bool => is_string($id))
        ->values()
        ->all();

    if ($commentIds !== [] || $mediaIds !== []) {
        $connection
            ->table((new MediaAssociation)->getTable())
            ->where(function (QueryBuilder $query) use ($commentIds, $mediaIds): void {
                if ($commentIds !== [] && $mediaIds !== []) {
                    $query
                        ->whereIn('associable_id', $commentIds)
                        ->orWhereIn('media_id', $mediaIds);
                } elseif ($commentIds !== []) {
                    $query->whereIn('associable_id', $commentIds);
                } else {
                    $query->whereIn('media_id', $mediaIds);
                }
            })
            ->delete();
    }

    foreach ($commentIds as $commentId) {
        $connection->table($commentTable)->where('id', $commentId)->delete();
    }

    $connection->table($commentTable)->where('id', $targetId)->delete();

    if ($mediaIds !== []) {
        $connection
            ->table((new Media)->getTable())
            ->whereIn('id', $mediaIds)
            ->delete();
    }

    DB::purge('comments_concurrency_target');
}

it('reloads one idempotent comment after a real database uniqueness race', function (): void {
    $connectionName = commentsConcurrencyPrepareConnection();
    $targetId = Str::uuid()->toString();
    $idempotencyKey = Str::uuid()->toString();

    try {
        commentsConcurrencyInsertTarget($targetId);
        $results = commentsConcurrencyRunWorkers($connectionName, [
            static function () use ($idempotencyKey, $targetId): array {
                $comment = app(CreateCommentAction::class)->execute(
                    ConcurrentCommentTarget::query()->findOrFail($targetId),
                    new CreateCommentData(
                        body: 'Exactly one concurrent comment',
                        idempotencyKey: $idempotencyKey,
                    ),
                    new CommentActorData('member', 'concurrent-author'),
                );

                return [
                    'id' => $comment->id,
                    'created' => $comment->wasRecentlyCreated,
                ];
            },
            static function () use ($idempotencyKey, $targetId): array {
                $comment = app(CreateCommentAction::class)->execute(
                    ConcurrentCommentTarget::query()->findOrFail($targetId),
                    new CreateCommentData(
                        body: 'Exactly one concurrent comment',
                        idempotencyKey: $idempotencyKey,
                    ),
                    new CommentActorData('member', 'concurrent-author'),
                );

                return [
                    'id' => $comment->id,
                    'created' => $comment->wasRecentlyCreated,
                ];
            },
        ]);
        $ids = array_values(array_filter(
            array_column($results, 'id'),
            static fn (mixed $id): bool => is_string($id),
        ));
        $createdStates = array_column($results, 'created');

        expect(array_filter(
            $results,
            static fn (array $result): bool => $result['ok'] !== true,
        ))->toBe([])
            ->and(array_unique($ids))->toHaveCount(1)
            ->and($createdStates)->toContain(true, false)
            ->and(DB::connection('comments_concurrency_target')
                ->table(CommentsConfiguration::table('comments'))
                ->where('idempotency_key', $idempotencyKey)
                ->count())->toBe(1);
    } finally {
        commentsConcurrencyCleanup($targetId);
    }
})->skip(
    fn (): bool => commentsConcurrencySkipReason() !== null,
    'The process concurrency gates require PostgreSQL/MySQL and pcntl.',
);

it('rejects one payload when concurrent requests reuse a key with different data', function (): void {
    $connectionName = commentsConcurrencyPrepareConnection();
    $targetId = Str::uuid()->toString();
    $idempotencyKey = Str::uuid()->toString();

    try {
        commentsConcurrencyInsertTarget($targetId);
        $worker = static function (string $body) use (
            $idempotencyKey,
            $targetId,
        ): array {
            $comment = app(CreateCommentAction::class)->execute(
                ConcurrentCommentTarget::query()->findOrFail($targetId),
                new CreateCommentData(
                    body: $body,
                    idempotencyKey: $idempotencyKey,
                ),
                new CommentActorData('member', 'concurrent-author'),
            );

            return ['id' => $comment->id, 'body' => $comment->body];
        };
        $results = commentsConcurrencyRunWorkers($connectionName, [
            static fn (): array => $worker('First canonical payload'),
            static fn (): array => $worker('Conflicting canonical payload'),
        ]);
        $successes = array_values(array_filter(
            $results,
            static fn (array $result): bool => $result['ok'] === true,
        ));
        $failures = array_values(array_filter(
            $results,
            static fn (array $result): bool => $result['ok'] !== true,
        ));

        expect($successes)->toHaveCount(1)
            ->and($failures)->toHaveCount(1)
            ->and($failures[0]['error'])
            ->toBe(CommentIdempotencyConflictException::class)
            ->and(DB::connection('comments_concurrency_target')
                ->table(CommentsConfiguration::table('comments'))
                ->where('idempotency_key', $idempotencyKey)
                ->count())->toBe(1);
    } finally {
        commentsConcurrencyCleanup($targetId);
    }
})->skip(
    fn (): bool => commentsConcurrencySkipReason() !== null,
    'The process concurrency gates require PostgreSQL/MySQL and pcntl.',
);

it('serializes a deleted reply restore against a competing delete', function (): void {
    $connectionName = commentsConcurrencyPrepareConnection();
    $targetId = Str::uuid()->toString();
    $parentId = Str::uuid()->toString();
    $replyId = Str::uuid()->toString();

    try {
        commentsConcurrencyInsertTarget($targetId);
        commentsConcurrencyInsertComment($parentId, $targetId);
        commentsConcurrencyInsertComment($replyId, $targetId, [
            'root_id' => $parentId,
            'parent_id' => $parentId,
            'depth' => 1,
            'revision' => 2,
            'deleted_by_type' => 'member',
            'deleted_by' => 'concurrent-author',
            'deleted_at' => now(),
        ]);
        $results = commentsConcurrencyRunWorkers($connectionName, [
            static function () use ($replyId): array {
                $restored = app(RestoreCommentAction::class)->execute(
                    $replyId,
                    new RestoreCommentData(2),
                    CommentActorData::system(),
                );

                return [
                    'operation' => 'restore',
                    'revision' => $restored->revision,
                ];
            },
            static function () use ($replyId): array {
                $deleted = app(DeleteCommentAction::class)->execute(
                    $replyId,
                    new DeleteCommentData(2),
                    CommentActorData::system(),
                );

                return ['operation' => 'delete', 'deleted' => $deleted];
            },
        ]);
        $successes = array_values(array_filter(
            $results,
            static fn (array $result): bool => $result['ok'] === true,
        ));
        $failures = array_values(array_filter(
            $results,
            static fn (array $result): bool => $result['ok'] !== true,
        ));
        $connection = DB::connection('comments_concurrency_target');
        $reply = $connection
            ->table(CommentsConfiguration::table('comments'))
            ->where('id', $replyId)
            ->first();

        if (! $reply instanceof stdClass) {
            throw new RuntimeException('The restored reply could not be inspected.');
        }

        expect($successes)->toHaveCount(1)
            ->and($successes[0]['operation'])->toBe('restore')
            ->and($successes[0]['revision'])->toBe(3)
            ->and($failures)->toHaveCount(1)
            ->and($failures[0]['error'])->toBeIn([
                InvalidCommentLifecycleException::class,
                StaleCommentException::class,
            ])
            ->and($reply)->not->toBeNull()
            ->and($reply->revision)->toBe(3)
            ->and($reply->deleted_at)->toBeNull()
            ->and($reply->status)->toBe('pending')
            ->and($connection
                ->table(CommentsConfiguration::table('comments'))
                ->where('id', $parentId)
                ->value('reply_count'))->toBe(1);
    } finally {
        commentsConcurrencyCleanup($targetId);
    }
})->skip(
    fn (): bool => commentsConcurrencySkipReason() !== null,
    'The process concurrency gates require PostgreSQL/MySQL and pcntl.',
);

it('never leaves an attachment on a concurrently anonymized comment', function (): void {
    $connectionName = commentsConcurrencyPrepareConnection();
    $targetId = Str::uuid()->toString();
    $commentId = Str::uuid()->toString();
    $mediaId = Str::uuid()->toString();

    try {
        commentsConcurrencyInsertTarget($targetId);
        commentsConcurrencyInsertComment($commentId, $targetId, [
            'body' => 'Identity under concurrent attachment mutation',
        ]);
        commentsConcurrencyInsertMedia($mediaId);
        $results = commentsConcurrencyRunWorkers($connectionName, [
            static function () use ($commentId, $mediaId): array {
                $association = app(AttachCommentMediaAction::class)->execute(
                    $commentId,
                    $mediaId,
                    CommentActorData::system(),
                );

                return [
                    'operation' => 'attach',
                    'association_id' => $association->id,
                ];
            },
            static function () use ($commentId): array {
                $comment = app(AnonymizeCommentAction::class)->execute(
                    $commentId,
                    new AnonymizeCommentData(1, 'Concurrent erasure'),
                    CommentActorData::system(),
                );

                return [
                    'operation' => 'anonymize',
                    'revision' => $comment->revision,
                ];
            },
        ]);
        $successes = array_values(array_filter(
            $results,
            static fn (array $result): bool => $result['ok'] === true,
        ));
        $failures = array_values(array_filter(
            $results,
            static fn (array $result): bool => $result['ok'] !== true,
        ));
        $connection = DB::connection('comments_concurrency_target');
        $comment = $connection
            ->table(CommentsConfiguration::table('comments'))
            ->where('id', $commentId)
            ->first();
        $associationCount = $connection
            ->table((new MediaAssociation)->getTable())
            ->where('associable_id', $commentId)
            ->where('collection', 'attachments')
            ->count();

        if (! $comment instanceof stdClass) {
            throw new RuntimeException('The attachment race comment could not be inspected.');
        }

        expect(count($successes) >= 1)->toBeTrue()
            ->and(array_filter(
                $failures,
                static fn (array $failure): bool => ! in_array(
                    $failure['error'],
                    [
                        CommentMutationBusyException::class,
                        ModelNotFoundException::class,
                        StaleCommentException::class,
                    ],
                    true,
                ),
            ))->toBe([])
            ->and($comment)->not->toBeNull()
            ->and($connection
                ->table((new Media)->getTable())
                ->where('id', $mediaId)
                ->exists())->toBeTrue();

        if ($comment->anonymized_at !== null) {
            expect($comment->revision)->toBe(2)
                ->and($comment->deleted_at)->not->toBeNull()
                ->and($comment->body)->toBe('')
                ->and($comment->actor_type)->toBeNull()
                ->and($associationCount)->toBe(0);
        } else {
            expect($comment->revision)->toBe(1)
                ->and($comment->deleted_at)->toBeNull()
                ->and($comment->body)
                ->toBe('Identity under concurrent attachment mutation')
                ->and($associationCount)->toBe(1);
        }
    } finally {
        commentsConcurrencyCleanup($targetId, [$mediaId]);
    }
})->skip(
    fn (): bool => commentsConcurrencySkipReason() !== null,
    'The process concurrency gates require PostgreSQL/MySQL and pcntl.',
);

it('preserves a live reply while reconciliation repairs stale counters', function (): void {
    $connectionName = commentsConcurrencyPrepareConnection();
    $targetId = Str::uuid()->toString();
    $parentId = Str::uuid()->toString();

    try {
        commentsConcurrencyInsertTarget($targetId);
        commentsConcurrencyInsertComment($parentId, $targetId, [
            'reply_count' => 5,
        ]);
        $results = commentsConcurrencyRunWorkers($connectionName, [
            static function () use ($targetId): array {
                $result = app(CommentStateReconciler::class)->reconcile(
                    ConcurrentCommentTarget::query()->findOrFail($targetId),
                    chunkSize: 1,
                    repair: true,
                    targetLabel: "concurrency:{$targetId}",
                );

                return [
                    'operation' => 'reconcile',
                    'repaired' => $result->repaired,
                    'remaining' => $result->remaining,
                ];
            },
            static function () use ($parentId, $targetId): array {
                $reply = app(CreateCommentAction::class)->execute(
                    ConcurrentCommentTarget::query()->findOrFail($targetId),
                    new CreateCommentData(
                        body: 'Live reply during reconciliation',
                        parentId: $parentId,
                    ),
                    CommentActorData::system(),
                );

                return ['operation' => 'reply', 'id' => $reply->id];
            },
        ]);

        expect(array_filter(
            $results,
            static fn (array $result): bool => $result['ok'] !== true,
        ))->toBe([]);

        $verification = commentsConcurrencyRunWorkers($connectionName, [
            static function () use ($targetId): array {
                $result = app(CommentStateReconciler::class)->reconcile(
                    ConcurrentCommentTarget::query()->findOrFail($targetId),
                    chunkSize: 1,
                    repair: true,
                    targetLabel: "concurrency:{$targetId}",
                );

                return [
                    'remaining' => $result->remaining,
                    'healthy' => $result->healthy,
                ];
            },
        ]);
        $connection = DB::connection('comments_concurrency_target');
        $replyCount = $connection
            ->table(CommentsConfiguration::table('comments'))
            ->where('parent_id', $parentId)
            ->count();

        expect($verification)->toHaveCount(1)
            ->and($verification[0]['ok'])->toBeTrue()
            ->and($verification[0]['remaining'])->toBe(0)
            ->and($verification[0]['healthy'])->toBeTrue()
            ->and($replyCount)->toBe(1)
            ->and($connection
                ->table(CommentsConfiguration::table('comments'))
                ->where('id', $parentId)
                ->value('reply_count'))->toBe(1);
    } finally {
        commentsConcurrencyCleanup($targetId);
    }
})->skip(
    fn (): bool => commentsConcurrencySkipReason() !== null,
    'The process concurrency gates require PostgreSQL/MySQL and pcntl.',
);

it('counts distinct concurrent reporters exactly once each', function (): void {
    $connectionName = commentsConcurrencyPrepareConnection();
    $targetId = Str::uuid()->toString();
    $commentId = Str::uuid()->toString();

    try {
        commentsConcurrencyInsertTarget($targetId);
        commentsConcurrencyInsertComment($commentId, $targetId);
        $worker = static function (string $reporterId) use ($commentId): array {
            $report = app(ReportCommentAction::class)->execute(
                $commentId,
                new ReportCommentData('concurrent-abuse'),
                new CommentActorData('member', $reporterId),
            );

            return ['id' => $report->id, 'reporter_id' => $report->reporter_id];
        };
        $results = commentsConcurrencyRunWorkers($connectionName, [
            static fn (): array => $worker('concurrent-reporter-a'),
            static fn (): array => $worker('concurrent-reporter-b'),
        ]);
        $connection = DB::connection('comments_concurrency_target');
        $comment = $connection
            ->table(CommentsConfiguration::table('comments'))
            ->where('id', $commentId)
            ->first();

        if (! $comment instanceof stdClass) {
            throw new RuntimeException('The concurrently reported comment could not be inspected.');
        }

        $reportIds = array_values(array_filter(
            array_column($results, 'id'),
            static fn (mixed $id): bool => is_string($id),
        ));

        expect(array_filter(
            $results,
            static fn (array $result): bool => $result['ok'] !== true,
        ))->toBe([])
            ->and(array_unique($reportIds))->toHaveCount(2)
            ->and($comment)->not->toBeNull()
            ->and($comment->report_count)->toBe(2)
            ->and($comment->open_report_count)->toBe(2)
            ->and($connection
                ->table(CommentsConfiguration::table('comment_reports'))
                ->where('comment_id', $commentId)
                ->count())->toBe(2);
    } finally {
        commentsConcurrencyCleanup($targetId);
    }
})->skip(
    fn (): bool => commentsConcurrencySkipReason() !== null,
    'The process concurrency gates require PostgreSQL/MySQL and pcntl.',
);
