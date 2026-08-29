<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Exceptions\CommentMutationLockConfigurationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Providers\CommentsServiceProvider;
use Nvl\Comments\Services\CommentMutationLock;
use Nvl\Comments\Services\CommentMutationLockStore;
use Nvl\Comments\Tests\Fixtures\TestCommentTargetResolver;
use Nvl\Media\Models\MediaAssociation;

/**
 * @return array{int, array<string, mixed>}
 */
function runCommentsDoctor(): array
{
    $exitCode = Artisan::call('nvl:comments:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return [$exitCode, $report];
}

it('reports complete schema and dependency readiness by default', function (): void {
    [$exitCode, $report] = runCommentsDoctor();

    expect($exitCode)->toBe(0)
        ->and($report['table.comments'])->toBeTrue()
        ->and($report['column.comments.body'])->toBeTrue()
        ->and($report['column.comment_reactions.actor_id'])->toBeTrue()
        ->and($report['column.comment_revisions.revision'])->toBeTrue()
        ->and($report['column.comment_reports.status'])->toBeTrue()
        ->and($report['column.comment_metadata_values.value_hash'])->toBeTrue()
        ->and($report['column_definition.comments.commentable_identity_hash'])->toBeTrue()
        ->and($report['column_definition.comments.root_id'])->toBeTrue()
        ->and($report['column_definition.comments.reply_count'])->toBeTrue()
        ->and($report['column_definition.comments.anonymized_at'])->toBeTrue()
        ->and($report['column_definition.comment_reactions.actor_identity_hash'])->toBeTrue()
        ->and($report['column_definition.comment_revisions.created_at'])->toBeTrue()
        ->and($report['column_definition.comment_reports.status'])->toBeTrue()
        ->and($report['index.comment_reactions.actor_type_unique'])->toBeTrue()
        ->and($report['index.comment_revisions.number_unique'])->toBeTrue()
        ->and($report['index.comment_reports.reporter_unique'])->toBeTrue()
        ->and($report['index.comments.idempotency_unique'])->toBeTrue()
        ->and($report['index.comment_metadata_values.owner_unique'])->toBeTrue()
        ->and($report['index.comment_metadata_values.lookup'])->toBeTrue()
        ->and($report['foreign_key.comments.parent'])->toBeTrue()
        ->and($report['foreign_key.comment_reports.comment'])->toBeTrue()
        ->and($report['foreign_key.comment_metadata_values.comment'])->toBeTrue()
        ->and($report['metadata.schemas_ready'])->toBeTrue()
        ->and($report['metadata.digest_key_ready'])->toBeTrue()
        ->and($report['metadata.strict_compatible'])->toBeTrue()
        ->and($report['targets'])->toBe(['article'])
        ->and($report['targets.ready'])->toBeTrue()
        ->and($report['attachments.connection_ready'])->toBeTrue()
        ->and($report['attachments.tables_ready'])->toBeTrue()
        ->and($report['mutation_lock.configuration_ready'])->toBeTrue()
        ->and($report['mutation_lock.ready'])->toBeTrue()
        ->and($report['healthy'])->toBeTrue()
        ->and(array_key_exists('routes.public_ready', $report))->toBeFalse()
        ->and(array_key_exists('policy.public_ready', $report))->toBeFalse()
        ->and(array_key_exists('routes.management_ready', $report))->toBeFalse()
        ->and(array_key_exists('policy.management_ready', $report))->toBeFalse();
});

it('registers timestamp-aware migration publishing and warns about duplicate ownership', function (): void {
    $migrationPath = realpath(dirname(__DIR__, 2).'/database/migrations');
    $publishableMigrationPaths = array_map(
        static fn (string $path): string|false => realpath($path),
        ServiceProvider::publishableMigrationPaths(),
    );

    expect(CommentsServiceProvider::pathsToPublish(
        CommentsServiceProvider::class,
        'comments-migrations',
    ))->not->toBeEmpty()
        ->and($publishableMigrationPaths)->toContain($migrationPath);

    $published = database_path(
        'migrations/2099_01_01_000000_create_comments_table.php',
    );
    file_put_contents($published, "<?php\n");

    try {
        $exitCode = Artisan::call('nvl:comments:doctor', ['--format' => 'json']);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($report['migrations.ownership'])->toMatchArray([
                'severity' => 'warning',
                'passed' => false,
            ])
            ->and($report['migrations.ownership']['message'])
            ->toContain('create_comments_table');

        [$strictExitCode, $strictReport] = runCommentsDoctor();

        expect($strictExitCode)->toBe(1)
            ->and($strictReport['healthy'])->toBeTrue();
    } finally {
        unlink($published);
    }
});

it('fails strict diagnostics when a configured package table lacks required columns', function (): void {
    Schema::create('comments_doctor_broken', function (Blueprint $table): void {
        $table->uuid('id')->primary();
    });
    config()->set('comments.tables.comments', 'comments_doctor_broken');

    [$exitCode, $report] = runCommentsDoctor();

    expect($exitCode)->toBe(1)
        ->and($report['table.comments'])->toBeTrue()
        ->and($report['column.comments.id'])->toBeTrue()
        ->and($report['column.comments.body'])->toBeFalse()
        ->and($report['healthy'])->toBeFalse();
});

it('fails strict diagnostics when a fingerprint column has the wrong type', function (): void {
    $driver = DB::connection()->getDriverName();

    Schema::table(CommentsTables::Comments, function (Blueprint $table) use ($driver): void {
        if ($driver === 'sqlite') {
            $table->text('commentable_identity_hash')->change();

            return;
        }

        $table->string('commentable_identity_hash', 64)->change();
    });

    [$exitCode, $report] = runCommentsDoctor();

    expect($exitCode)->toBe(1)
        ->and($report['column.comments.commentable_identity_hash'])->toBeTrue()
        ->and($report['column_definition.comments.commentable_identity_hash'])->toBeFalse()
        ->and($report['healthy'])->toBeFalse();
});

it('fails strict diagnostics when a UUID column has the wrong type', function (): void {
    $driver = DB::connection()->getDriverName();

    Schema::table(CommentsTables::Comments, function (Blueprint $table) use ($driver): void {
        if ($driver === 'sqlite') {
            $table->text('root_id')->nullable()->change();

            return;
        }

        $table->string('root_id', 36)->nullable()->change();
    });

    [$exitCode, $report] = runCommentsDoctor();

    expect($exitCode)->toBe(1)
        ->and($report['column.comments.root_id'])->toBeTrue()
        ->and($report['column_definition.comments.root_id'])->toBeFalse()
        ->and($report['healthy'])->toBeFalse();
});

it('fails strict diagnostics when identity nullability drifts', function (): void {
    Schema::table(CommentsTables::Reactions, function (Blueprint $table): void {
        $table->char('actor_identity_hash', 64)->nullable()->change();
    });

    [$exitCode, $report] = runCommentsDoctor();

    expect($exitCode)->toBe(1)
        ->and($report['column.comment_reactions.actor_identity_hash'])->toBeTrue()
        ->and($report['column_definition.comment_reactions.actor_identity_hash'])->toBeFalse()
        ->and($report['healthy'])->toBeFalse();
});

it('fails strict diagnostics when a counter default drifts', function (): void {
    Schema::table(CommentsTables::Comments, function (Blueprint $table): void {
        $table->unsignedInteger('reply_count')->default(9)->change();
    });

    [$exitCode, $report] = runCommentsDoctor();

    expect($exitCode)->toBe(1)
        ->and($report['column.comments.reply_count'])->toBeTrue()
        ->and($report['column_definition.comments.reply_count'])->toBeFalse()
        ->and($report['healthy'])->toBeFalse();
});

it('fails strict diagnostics when a lifecycle timestamp has the wrong type', function (): void {
    Schema::table(CommentsTables::Comments, function (Blueprint $table): void {
        $table->string('anonymized_at', 255)->nullable()->change();
    });

    [$exitCode, $report] = runCommentsDoctor();

    expect($exitCode)->toBe(1)
        ->and($report['column.comments.anonymized_at'])->toBeTrue()
        ->and($report['column_definition.comments.anonymized_at'])->toBeFalse()
        ->and($report['healthy'])->toBeFalse();
});

it('fails strict diagnostics when an idempotency constraint is missing', function (): void {
    Schema::table(CommentsTables::Reactions, function (Blueprint $table): void {
        $table->dropUnique('comment_reactions_actor_type_unique');
    });

    [$exitCode, $report] = runCommentsDoctor();

    expect($exitCode)->toBe(1)
        ->and($report['index.comment_reactions.actor_type_unique'])->toBeFalse()
        ->and($report['healthy'])->toBeFalse();
});

it('fails strict diagnostics for a configured target resolver with a mismatched alias', function (): void {
    config()->set('comments.targets', [
        'wrong-alias' => TestCommentTargetResolver::class,
    ]);

    [$exitCode, $report] = runCommentsDoctor();

    expect($exitCode)->toBe(1)
        ->and($report['targets.ready'])->toBeFalse()
        ->and($report['healthy'])->toBeFalse();
});

it('checks Media attachment tables only while attachments are enabled', function (): void {
    Schema::dropIfExists((new MediaAssociation)->getTable());

    [$enabledExitCode, $enabledReport] = runCommentsDoctor();

    expect($enabledExitCode)->toBe(1)
        ->and($enabledReport['attachments.connection_ready'])->toBeTrue()
        ->and($enabledReport['attachments.tables_ready'])->toBeFalse()
        ->and($enabledReport['healthy'])->toBeFalse();

    config()->set('comments.attachments.enabled', false);
    [$disabledExitCode, $disabledReport] = runCommentsDoctor();

    expect($disabledExitCode)->toBe(0)
        ->and($disabledReport['attachments'])->toBeFalse()
        ->and($disabledReport['attachments.disabled_state_ready'])->toBeTrue()
        ->and($disabledReport['healthy'])->toBeTrue()
        ->and(array_key_exists('attachments.connection_ready', $disabledReport))->toBeFalse()
        ->and(array_key_exists('attachments.tables_ready', $disabledReport))->toBeFalse();
});

it('rejects stringly typed security switches and malformed middleware', function (): void {
    config()->set('comments.attachments.enabled', 'false');

    [$stringExitCode, $stringReport] = runCommentsDoctor();

    expect($stringExitCode)->toBe(1)
        ->and($stringReport['configuration.values'])->toBeFalse()
        ->and($stringReport['attachments'])->toBeFalse()
        ->and($stringReport['healthy'])->toBeFalse();

    config()->set('comments.attachments.enabled', true);
    config()->set('comments.routes.public.middleware', ['api', null]);

    [$middlewareExitCode, $middlewareReport] = runCommentsDoctor();

    expect($middlewareExitCode)->toBe(1)
        ->and($middlewareReport['configuration.values'])->toBeFalse()
        ->and($middlewareReport['healthy'])->toBeFalse();
});

it('checks route and policy readiness only for enabled surfaces', function (): void {
    config()->set('comments.routes.public.enabled', true);
    require dirname(__DIR__, 2).'/routes/api.php';
    Route::getRoutes()->refreshNameLookups();

    [$publicExitCode, $publicReport] = runCommentsDoctor();

    expect($publicExitCode)->toBe(0)
        ->and($publicReport['routes.public_ready'])->toBeTrue()
        ->and($publicReport['routes.public_throttled'])->toBeTrue()
        ->and($publicReport['policy.public_ready'])->toBeTrue()
        ->and($publicReport['healthy'])->toBeTrue();

    config()->set('comments.routes.public.enabled', false);
    config()->set('comments.routes.management.enabled', true);
    require dirname(__DIR__, 2).'/routes/api.php';
    Route::getRoutes()->refreshNameLookups();

    [$defaultPolicyExitCode, $defaultPolicyReport] = runCommentsDoctor();

    expect($defaultPolicyExitCode)->toBe(1)
        ->and($defaultPolicyReport['routes.management_ready'])->toBeTrue()
        ->and($defaultPolicyReport['routes.management_authenticated'])->toBeTrue()
        ->and($defaultPolicyReport['routes.management_throttled'])->toBeTrue()
        ->and($defaultPolicyReport['policy.management_ready'])->toBeFalse()
        ->and($defaultPolicyReport['query_scope.management_ready'])->toBeFalse()
        ->and($defaultPolicyReport['healthy'])->toBeFalse();

    $boundary = new class implements CommentAuthorization, CommentQueryScope
    {
        /**
         * Allow every ability for command-readiness coverage.
         *
         * @param  array<string, mixed>  $context
         */
        public function allows(
            CommentAbility $ability,
            CommentActorData $actor,
            ?Comment $comment = null,
            ?Model $target = null,
            CommentAudience $audience = CommentAudience::Public,
            array $context = [],
        ): bool {
            return true;
        }

        /**
         * Keep management reads target-bound without additional fixture constraints.
         *
         * @param  Builder<Comment>  $query
         */
        public function scopeComments(
            Builder $query,
            CommentActorData $actor,
            Model $target,
            CommentAudience $audience,
            CommentAbility $ability,
        ): void {}
    };
    app()->instance(CommentAuthorization::class, $boundary);
    app()->instance(CommentQueryScope::class, $boundary);

    [$customPolicyExitCode, $customPolicyReport] = runCommentsDoctor();

    expect($customPolicyExitCode)->toBe(0)
        ->and($customPolicyReport['routes.management_ready'])->toBeTrue()
        ->and($customPolicyReport['routes.management_authenticated'])->toBeTrue()
        ->and($customPolicyReport['routes.management_throttled'])->toBeTrue()
        ->and($customPolicyReport['policy.management_ready'])->toBeTrue()
        ->and($customPolicyReport['query_scope.management_ready'])->toBeTrue()
        ->and($customPolicyReport['healthy'])->toBeTrue();

    config()->set('comments.routes.management.middleware', ['api']);

    [$unprotectedExitCode, $unprotectedReport] = runCommentsDoctor();

    expect($unprotectedExitCode)->toBe(1)
        ->and($unprotectedReport['routes.management_authenticated'])->toBeFalse()
        ->and($unprotectedReport['routes.management_throttled'])->toBeFalse()
        ->and($unprotectedReport['healthy'])->toBeFalse();
});

it('rejects malformed mutation lock values without coercion', function (
    string $key,
    mixed $value,
    string $message,
): void {
    config()->set($key, $value);

    expect(fn (): bool => app(CommentMutationLock::class)->execute(
        'comment-id',
        static fn (): bool => true,
    ))->toThrow(CommentMutationLockConfigurationException::class, $message);

    [$exitCode, $report] = runCommentsDoctor();

    expect($exitCode)->toBe(1)
        ->and($report['mutation_lock.configuration_ready'])->toBeFalse()
        ->and($report['mutation_lock.ready'])->toBeFalse()
        ->and($report['healthy'])->toBeFalse();
})->with([
    'group' => [
        'comments.mutation_lock',
        'enabled',
        'comments.mutation_lock must be an array.',
    ],
    'missing local-store declaration' => [
        'comments.mutation_lock',
        [
            'enabled' => true,
            'store' => null,
            'seconds' => 300,
            'wait_seconds' => 30,
        ],
        'comments.mutation_lock.allow_local_store must be a boolean.',
    ],
    'enabled string' => [
        'comments.mutation_lock.enabled',
        'false',
        'comments.mutation_lock.enabled must be a boolean.',
    ],
    'store array' => [
        'comments.mutation_lock.store',
        ['file'],
        'comments.mutation_lock.store must be null or a non-blank cache store name.',
    ],
    'blank store' => [
        'comments.mutation_lock.store',
        '   ',
        'comments.mutation_lock.store must be null or a non-blank cache store name.',
    ],
    'lease string' => [
        'comments.mutation_lock.seconds',
        '300',
        'comments.mutation_lock.seconds must be a positive integer.',
    ],
    'zero lease' => [
        'comments.mutation_lock.seconds',
        0,
        'comments.mutation_lock.seconds must be a positive integer.',
    ],
    'zero wait' => [
        'comments.mutation_lock.wait_seconds',
        0,
        'comments.mutation_lock.wait_seconds must be a positive integer.',
    ],
    'local opt-in string' => [
        'comments.mutation_lock.allow_local_store',
        'true',
        'comments.mutation_lock.allow_local_store must be a boolean.',
    ],
]);

it('rejects unsafe and non-locking mutation stores descriptively', function (
    string $storeName,
    string $driver,
    bool $allowLocalStore,
    string $message,
): void {
    config()->set("cache.stores.{$storeName}", ['driver' => $driver]);
    config()->set('comments.mutation_lock.store', $storeName);
    config()->set('comments.mutation_lock.allow_local_store', $allowLocalStore);

    expect(fn (): bool => app(CommentMutationLock::class)->execute(
        'comment-id',
        static fn (): bool => true,
    ))->toThrow(CommentMutationLockConfigurationException::class, $message);

    [$exitCode, $report] = runCommentsDoctor();

    expect($exitCode)->toBe(1)
        ->and($report['mutation_lock.configuration_ready'])->toBeTrue()
        ->and($report['mutation_lock.ready'])->toBeFalse()
        ->and($report['healthy'])->toBeFalse();
})->with([
    'process-local array' => [
        'comments_array',
        'array',
        true,
        'uses the [array] driver, which is unsafe for comment mutation locks',
    ],
    'null store' => [
        'comments_null',
        'null',
        true,
        'uses the [null] driver, which is unsafe for comment mutation locks',
    ],
    'cross-domain failover' => [
        'comments_failover',
        'failover',
        true,
        'uses the [failover] driver, which is unsafe for comment mutation locks',
    ],
    'unapproved local file' => [
        'comments_file',
        'file',
        false,
        'uses the file driver, which is single-host only',
    ],
    'non-locking driver' => [
        'comments_apc',
        'apc',
        true,
        "does not implement Laravel's LockProvider contract",
    ],
]);

it('rejects undefined mutation stores with the configured name', function (): void {
    config()->set('comments.mutation_lock.store', 'missing_comments_store');

    expect(fn (): bool => app(CommentMutationLock::class)->execute(
        'comment-id',
        static fn (): bool => true,
    ))->toThrow(
        CommentMutationLockConfigurationException::class,
        'Cache store [missing_comments_store] configured for comment mutation locks is not defined.',
    );
});

it('validates the application default when no dedicated mutation store is configured', function (): void {
    config()->set('comments.mutation_lock.store', null);
    config()->set('cache.default', 'array');

    expect(fn (): bool => app(CommentMutationLock::class)->execute(
        'comment-id',
        static fn (): bool => true,
    ))->toThrow(
        CommentMutationLockConfigurationException::class,
        'Cache store [array] uses the [array] driver, which is unsafe for comment mutation locks.',
    );

    config()->set('cache.default', ['file']);

    expect(fn (): bool => app(CommentMutationLock::class)->execute(
        'comment-id',
        static fn (): bool => true,
    ))->toThrow(
        CommentMutationLockConfigurationException::class,
        'cache.default must be a non-blank cache store name when comments.mutation_lock.store is null.',
    );
});

it('permits file locks only by explicit single-host opt-in and exact false disables locking', function (): void {
    config()->set('comments.mutation_lock.store', 'file');
    config()->set('comments.mutation_lock.allow_local_store', true);

    $locked = app(CommentMutationLock::class)->execute(
        'comment-id',
        static fn (): string => 'locked',
    );

    config()->set('comments.mutation_lock.enabled', false);
    config()->set('comments.mutation_lock.store', 'array');
    config()->set('comments.mutation_lock.allow_local_store', false);

    $disabled = app(CommentMutationLock::class)->execute(
        'comment-id',
        static fn (): string => 'disabled',
    );

    expect($locked)->toBe('locked')
        ->and($disabled)->toBe('disabled');
});

it('requires mutation locking in strict Doctor and accepts shared database and Redis providers', function (): void {
    config()->set('comments.mutation_lock.enabled', false);

    [$disabledExitCode, $disabledReport] = runCommentsDoctor();

    config()->set('comments.mutation_lock.enabled', true);
    config()->set('cache.stores.comments_database', [
        'driver' => 'database',
        'connection' => config('database.default'),
        'table' => 'cache',
        'lock_connection' => config('database.default'),
        'lock_table' => 'cache_locks',
    ]);
    config()->set('comments.mutation_lock.store', 'comments_database');

    [, $databaseReport] = runCommentsDoctor();
    $databaseProvider = app(CommentMutationLockStore::class)->provider(
        'comments_database',
        false,
    );

    config()->set('cache.stores.comments_redis', [
        'driver' => 'redis',
        'connection' => 'cache',
        'lock_connection' => 'default',
    ]);
    config()->set('comments.mutation_lock.store', 'comments_redis');

    [, $redisReport] = runCommentsDoctor();
    $redisProvider = app(CommentMutationLockStore::class)->provider(
        'comments_redis',
        false,
    );

    expect($disabledExitCode)->toBe(1)
        ->and($disabledReport['mutation_lock.configuration_ready'])->toBeTrue()
        ->and($disabledReport['mutation_lock.ready'])->toBeFalse()
        ->and($disabledReport['healthy'])->toBeFalse()
        ->and($databaseReport['mutation_lock.configuration_ready'])->toBeTrue()
        ->and($databaseReport['mutation_lock.ready'])->toBeTrue()
        ->and($databaseProvider)->toBeInstanceOf(LockProvider::class)
        ->and($redisReport['mutation_lock.configuration_ready'])->toBeTrue()
        ->and($redisReport['mutation_lock.ready'])->toBeTrue()
        ->and($redisProvider)->toBeInstanceOf(LockProvider::class);
});
