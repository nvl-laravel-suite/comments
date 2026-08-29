<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Comments\Actions\AnonymizeCommentAction;
use Nvl\Comments\Actions\CreateCommentAction;
use Nvl\Comments\Actions\DeleteCommentAction;
use Nvl\Comments\Actions\DeleteLatestTargetCommentAction;
use Nvl\Comments\Actions\FindLatestTargetCommentAction;
use Nvl\Comments\Actions\RestoreCommentRevisionAction;
use Nvl\Comments\Actions\UpdateCommentAction;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\AnonymizeCommentData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Data\Mutations\DeleteCommentData;
use Nvl\Comments\Data\Mutations\RestoreCommentRevisionData;
use Nvl\Comments\Data\Mutations\UpdateCommentData;
use Nvl\Comments\Data\Queries\CommentSelectorData;
use Nvl\Comments\Definitions\CommentMetadataField;
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentMetadataValueType;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\CommentMetadataValue;
use Nvl\Comments\Services\CommentMetadataRegistry;
use Nvl\Comments\Services\CommentProjectionFactory;
use Nvl\Comments\Tests\Fixtures\DottedCommentMetadataSchema;
use Nvl\Comments\Tests\Fixtures\DuplicateCommentMetadataSchema;
use Nvl\Comments\Tests\Fixtures\OverlongCommentMetadataNamespaceSchema;
use Nvl\Comments\Tests\Fixtures\TestCommentMetadataSchema;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;

/**
 * Rebuild the metadata registry after changing test-only schema configuration.
 *
 * @param  list<class-string>  $schemas
 */
function commentsMetadataConfigure(array $schemas, bool $strict = false): CommentMetadataRegistry
{
    config()->set([
        'comments.metadata.schemas' => $schemas,
        'comments.metadata.strict' => $strict,
        'comments.metadata.digest_key' => 'comments-metadata-test-key',
    ]);
    app()->forgetInstance(CommentMetadataRegistry::class);

    return app(CommentMetadataRegistry::class);
}

it('publishes bounded registered metadata defaults', function (): void {
    expect(config('comments.metadata'))->toBe([
        'strict' => false,
        'maximum_bytes' => 16_384,
        'maximum_registered_fields' => 50,
        'digest_key' => null,
        'schemas' => [],
    ]);
});

it('creates the portable hash-only metadata index schema', function (): void {
    $table = CommentsTables::get(CommentsTables::MetadataValues);

    expect(Schema::hasColumns($table, [
        'id',
        'comment_id',
        'schema_namespace',
        'field_name',
        'value_type',
        'value_hash',
    ]))->toBeTrue();
});

it('adds metadata storage to an existing released schema and refuses application-owned targets', function (): void {
    config()->set('database.connections.comments_metadata_upgrade', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    $defaultConnection = config('database.default');
    config()->set('database.default', 'comments_metadata_upgrade');
    config()->set('comments.connection', null);

    foreach ([
        '2026_07_27_110001_create_comments_table.php',
        '2026_07_27_110002_create_comment_reactions_table.php',
        '2026_07_27_110003_create_comment_revisions_table.php',
        '2026_07_27_110004_create_comment_reports_table.php',
    ] as $migration) {
        (require __DIR__."/../../database/migrations/{$migration}")->up();
    }

    $metadataMigration = require __DIR__
        .'/../../database/migrations/2026_08_28_000000_create_comment_metadata_values_table.php';
    $metadataMigration->up();

    expect(Schema::connection('comments_metadata_upgrade')->hasTable(
        CommentsTables::MetadataValues,
    ))->toBeTrue();

    config()->set('comments.tables.comment_metadata_values', 'tenant_comment_metadata_values');
    $metadataMigration->down();

    expect(fn () => $metadataMigration->up())->toThrow(
        LogicException::class,
        'canonical tables',
    )->and(Schema::connection('comments_metadata_upgrade')->hasTable(
        'tenant_comment_metadata_values',
    ))->toBeFalse();

    config()->set('database.default', $defaultConnection);
});

it('uses actual application-owned metadata storage for every write and selector lifecycle', function (): void {
    $table = 'tenant_comment_metadata_values';
    Schema::create($table, function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('comment_id');
        $table->string('schema_namespace', 100);
        $table->string('field_name', 64);
        $table->string('value_type', 16);
        $table->char('value_hash', 64);
        $table->timestamps();
        $table->foreign('comment_id')->references('id')->on('comments')->cascadeOnDelete();
        $table->unique(['comment_id', 'schema_namespace', 'field_name']);
        $table->index(['schema_namespace', 'field_name', 'value_hash']);
    });
    config()->set('comments.tables.comment_metadata_values', $table);
    commentsMetadataConfigure([TestCommentMetadataSchema::class]);
    $target = TestCommentTarget::query()->create(['name' => 'Application-owned metadata']);
    $actor = new CommentActorData('member', 'application-storage-author');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Custom storage', metadata: ['workflow_event' => 'created']),
        $actor,
        CommentAudience::Member,
    );

    expect(DB::table($table)->where('comment_id', $comment->id)->count())->toBe(1)
        ->and(app(FindLatestTargetCommentAction::class)->execute(
            $target,
            $actor,
            new CommentSelectorData(metadataEquals: ['workflow.event' => 'created']),
            CommentAudience::Member,
        )?->id)->toBe($comment->id);

    $updated = app(UpdateCommentAction::class)->execute(
        $comment,
        new UpdateCommentData(
            body: 'Custom storage updated',
            expectedRevision: 1,
            metadata: ['workflow_event' => 'updated'],
        ),
        $actor,
        CommentAudience::Member,
    );

    expect(app(FindLatestTargetCommentAction::class)->execute(
        $target,
        $actor,
        new CommentSelectorData(metadataEquals: ['workflow.event' => 'updated']),
        CommentAudience::Member,
    )?->id)->toBe($updated->id)
        ->and(app(DeleteCommentAction::class)->execute(
            $updated,
            new DeleteCommentData(expectedRevision: 2),
            $actor,
            CommentAudience::Member,
        ))->toBeTrue()
        ->and(DB::table($table)->where('comment_id', $comment->id)->exists())->toBeFalse();
});

it('validates registered values while retaining compatible legacy metadata internally', function (): void {
    commentsMetadataConfigure([TestCommentMetadataSchema::class]);
    $target = TestCommentTarget::query()->create(['name' => 'Metadata validation']);
    $actor = new CommentActorData('member', 'metadata-author');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData(
            body: 'Registered metadata',
            metadata: [
                'legacy_private' => 'must-never-project',
                'workflow_sequence' => 7,
                'workflow_event' => 'submitted',
                'workflow_approved' => true,
                'recipient_user_id' => null,
            ],
        ),
        $actor,
        CommentAudience::Member,
    );

    expect(array_keys($comment->metadata ?? []))->toBe([
        'legacy_private',
        'recipient_user_id',
        'workflow_approved',
        'workflow_event',
        'workflow_sequence',
    ])
        ->and(CommentMetadataValue::query()->where('comment_id', $comment->id)->count())->toBe(4)
        ->and(json_encode(CommentMetadataValue::query()->get()->toArray()))
        ->not->toContain('submitted', 'must-never-project');

    expect(fn () => app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData(
            body: 'Wrong metadata type',
            metadata: ['workflow_sequence' => 'private-wrong-value'],
        ),
        $actor,
        CommentAudience::Member,
    ))->toThrow(InvalidCommentMutationException::class)
        ->and(fn () => app(CreateCommentAction::class)->execute(
            $target,
            new CreateCommentData(
                body: 'Long metadata value',
                metadata: ['workflow_note' => 'private-value-over-limit'],
            ),
            $actor,
            CommentAudience::Member,
        ))->toThrow(InvalidCommentMutationException::class);
});

it('keeps raw metadata out of mutation events log contexts and exceptions', function (): void {
    commentsMetadataConfigure([TestCommentMetadataSchema::class]);
    $target = TestCommentTarget::query()->create(['name' => 'Metadata privacy outputs']);
    $actor = new CommentActorData('member', 'privacy-output-author');
    Event::fake([CommentChanged::class]);
    app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Privacy outputs', metadata: [
            'legacy_private' => 'raw-private-value',
            'workflow_event' => 'registered-private-value',
        ]),
        $actor,
        CommentAudience::Member,
    );

    Event::assertDispatched(CommentChanged::class, function (CommentChanged $event): bool {
        $serialized = serialize($event);
        $context = json_encode(['event' => $event], JSON_THROW_ON_ERROR);
        $path = storage_path('logs/comment-metadata-privacy.log');
        @unlink($path);
        Log::build(['driver' => 'single', 'path' => $path])
            ->info('Comment changed', ['event' => $event]);
        $logged = (string) file_get_contents($path);
        @unlink($path);

        expect($serialized)->not->toContain(
            'legacy_private',
            'raw-private-value',
            'registered-private-value',
        )->and($context)->not->toContain(
            'legacy_private',
            'raw-private-value',
            'registered-private-value',
        )->and($logged)->not->toContain(
            'legacy_private',
            'raw-private-value',
            'registered-private-value',
        );

        return true;
    });

    commentsMetadataConfigure([TestCommentMetadataSchema::class], strict: true);

    try {
        app(CreateCommentAction::class)->execute(
            $target,
            new CreateCommentData('Private rejection', metadata: [
                'raw_private_key' => 'raw-private-exception-value',
            ]),
            $actor,
            CommentAudience::Member,
        );
    } catch (InvalidCommentMutationException $exception) {
        expect((string) $exception)->not->toContain(
            'raw_private_key',
            'raw-private-exception-value',
        );

        return;
    }

    $this->fail('Strict metadata accepted a private unregistered key.');
});

it('round-trips dotted namespaces and enforces metadata index storage widths', function (): void {
    commentsMetadataConfigure([DottedCommentMetadataSchema::class]);
    $target = TestCommentTarget::query()->create(['name' => 'Dotted metadata namespace']);
    $actor = new CommentActorData('member', 'dotted-author');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Dotted namespace', metadata: [
            'sales_workflow_event' => 'submitted',
        ]),
        $actor,
        CommentAudience::Member,
    );

    expect(CommentMetadataValue::query()->where('comment_id', $comment->id)->sole()->only([
        'schema_namespace',
        'field_name',
    ]))->toBe([
        'schema_namespace' => 'sales.workflow',
        'field_name' => 'event',
    ])->and(app(FindLatestTargetCommentAction::class)->execute(
        $target,
        $actor,
        new CommentSelectorData(metadataEquals: ['sales.workflow.event' => 'submitted']),
        CommentAudience::Member,
    )?->id)->toBe($comment->id);

    config()->set('comments.metadata.schemas', [OverlongCommentMetadataNamespaceSchema::class]);
    app()->forgetInstance(CommentMetadataRegistry::class);

    expect(fn () => app(CommentMetadataRegistry::class))->toThrow(
        InvalidArgumentException::class,
        'namespace',
    )->and(fn () => new CommentMetadataField(
        name: str_repeat('a', 65),
        storageKey: 'long_field',
        type: CommentMetadataValueType::String,
        nullable: false,
        mutable: true,
        queryable: true,
        visibleTo: [CommentAudience::Member],
    ))->toThrow(InvalidArgumentException::class, 'field');
});

it('rejects unknown keys in strict mode without disclosing their names or values', function (): void {
    commentsMetadataConfigure([TestCommentMetadataSchema::class], strict: true);
    $target = TestCommentTarget::query()->create(['name' => 'Strict metadata']);

    try {
        app(CreateCommentAction::class)->execute(
            $target,
            new CreateCommentData(
                body: 'Strict metadata',
                metadata: ['private_unknown_key' => 'private-unknown-value'],
            ),
            new CommentActorData('member', 'strict-author'),
            CommentAudience::Member,
        );
    } catch (InvalidCommentMutationException $exception) {
        expect($exception->getMessage())
            ->not->toContain('private_unknown_key', 'private-unknown-value');

        return;
    }

    $this->fail('Strict metadata accepted an unregistered key.');
});

it('enforces independent metadata byte and registered field limits', function (): void {
    commentsMetadataConfigure([TestCommentMetadataSchema::class]);
    config()->set([
        'comments.content.maximum_bytes' => 1000,
        'comments.metadata.maximum_bytes' => 20,
    ]);
    $target = TestCommentTarget::query()->create(['name' => 'Metadata limits']);
    $actor = new CommentActorData('member', 'limit-author');

    expect(fn () => app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Small body', metadata: ['legacy' => str_repeat('x', 30)]),
        $actor,
        CommentAudience::Member,
    ))->toThrow(InvalidCommentMutationException::class, 'metadata byte limit');

    config()->set([
        'comments.metadata.maximum_bytes' => 16_384,
        'comments.metadata.maximum_registered_fields' => 1,
    ]);

    expect(fn () => app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Field limit', metadata: [
            'workflow_event' => 'submitted',
            'workflow_sequence' => 1,
        ]),
        $actor,
        CommentAudience::Member,
    ))->toThrow(InvalidCommentMutationException::class, 'registered field limit');
});

it('rejects duplicate storage ownership during registry resolution', function (): void {
    config()->set('comments.metadata.schemas', [
        TestCommentMetadataSchema::class,
        DuplicateCommentMetadataSchema::class,
    ]);
    app()->forgetInstance(CommentMetadataRegistry::class);

    expect(fn () => app(CommentMetadataRegistry::class))
        ->toThrow(InvalidArgumentException::class, 'storage key');
});

it('projects only registered fields declared for each audience and omits tombstones', function (): void {
    commentsMetadataConfigure([TestCommentMetadataSchema::class]);
    $target = TestCommentTarget::query()->create(['name' => 'Metadata projection']);
    $actor = new CommentActorData('member', 'projection-author');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Projected metadata', metadata: [
            'legacy_private' => 'must-never-project',
            'workflow_event' => 'submitted',
            'workflow_sequence' => 9,
            'workflow_approved' => true,
            'recipient_user_id' => null,
        ]),
        $actor,
        CommentAudience::Member,
    );
    $projections = app(CommentProjectionFactory::class);
    $public = $projections->publicComment($comment, $target)->toArray();
    $member = $projections->memberComment($comment, $target, $actor)->toArray();
    $management = $projections->managementComment($comment, $target, $actor)->toArray();

    expect($public['metadata'])->toBe([[
        'namespace' => 'workflow',
        'values' => ['sequence' => 9],
    ]])
        ->and($member['metadata'])->toBe([[
            'namespace' => 'workflow',
            'values' => ['event' => 'submitted', 'approved' => true],
        ]])
        ->and($management['metadata'])->toBe([[
            'namespace' => 'workflow',
            'values' => ['event' => 'submitted', 'recipient' => null],
        ]])
        ->and(json_encode([$public, $member, $management]))
        ->not->toContain('legacy_private', 'must-never-project');

    $comment->delete();

    expect($projections->publicComment($comment->refresh(), $target)->toArray())
        ->not->toHaveKey('metadata');
});

it('matches queryable string integer boolean and null metadata selectors', function (): void {
    commentsMetadataConfigure([TestCommentMetadataSchema::class]);
    $target = TestCommentTarget::query()->create(['name' => 'Metadata selector']);
    $actor = new CommentActorData('member', 'selector-author');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Selected metadata', metadata: [
            'workflow_event' => 'submitted',
            'workflow_sequence' => 11,
            'workflow_approved' => true,
            'recipient_user_id' => null,
        ]),
        $actor,
        CommentAudience::Member,
    );

    expect(DB::connection()->getDriverName())->toBeIn(['sqlite', 'mysql', 'mariadb', 'pgsql']);

    foreach ([
        'workflow.event' => 'submitted',
        'workflow.sequence' => 11,
        'workflow.approved' => true,
        'workflow.recipient' => null,
    ] as $alias => $value) {
        $found = app(FindLatestTargetCommentAction::class)->execute(
            $target,
            $actor,
            new CommentSelectorData(metadataEquals: [$alias => $value]),
            CommentAudience::Member,
        );

        expect($found?->id)->toBe($comment->id);
    }

    expect(fn () => new CommentSelectorData(metadataEquals: array_fill_keys(
        array_map(static fn (int $index): string => "workflow.field_{$index}", range(1, 11)),
        true,
    )))->toThrow(InvalidArgumentException::class, 'at most 10')
        ->and(fn () => app(FindLatestTargetCommentAction::class)->execute(
            $target,
            $actor,
            new CommentSelectorData(metadataEquals: ['workflow.note' => null]),
            CommentAudience::Member,
        ))->toThrow(InvalidArgumentException::class, 'queryable');
});

it('deletes the latest metadata match without exposing a comment model', function (): void {
    commentsMetadataConfigure([TestCommentMetadataSchema::class]);
    $target = TestCommentTarget::query()->create(['name' => 'Metadata deletion']);
    $actor = new CommentActorData('member', 'deletion-author');
    $create = app(CreateCommentAction::class);
    $older = $create->execute(
        $target,
        new CreateCommentData('Older deletion match', metadata: [
            'workflow_event' => 'delete-me',
        ]),
        $actor,
        CommentAudience::Member,
    );
    $newer = $create->execute(
        $target,
        new CreateCommentData('Newer deletion match', metadata: [
            'workflow_event' => 'delete-me',
        ]),
        $actor,
        CommentAudience::Member,
    );
    $older->forceFill(['created_at' => now()->subMinute()])->saveQuietly();
    $direct = $create->execute(
        $target,
        new CreateCommentData('Direct deletion match', metadata: [
            'workflow_event' => 'direct-delete',
        ]),
        $actor,
        CommentAudience::Member,
    );

    $deleted = app(DeleteLatestTargetCommentAction::class)->execute(
        $target,
        new CommentSelectorData(metadataEquals: ['workflow.event' => 'delete-me']),
        $actor,
        CommentAudience::Member,
    );

    expect($deleted)->toBeTrue()
        ->and($newer->refresh()->trashed())->toBeTrue()
        ->and(CommentMetadataValue::query()->where('comment_id', $newer->id)->exists())
        ->toBeFalse()
        ->and($older->refresh()->trashed())->toBeFalse()
        ->and(app(DeleteCommentAction::class)->execute(
            $direct,
            new DeleteCommentData(expectedRevision: 1),
            $actor,
            CommentAudience::Member,
        ))->toBeTrue()
        ->and(CommentMetadataValue::query()->where('comment_id', $direct->id)->exists())
        ->toBeFalse()
        ->and(app(DeleteLatestTargetCommentAction::class)->execute(
            $target,
            new CommentSelectorData(metadataEquals: ['workflow.event' => 'missing']),
            $actor,
            CommentAudience::Member,
        ))->toBeFalse();
});

it('synchronizes indexes across updates revision restore and anonymization', function (): void {
    commentsMetadataConfigure([TestCommentMetadataSchema::class]);
    $target = TestCommentTarget::query()->create(['name' => 'Metadata lifecycle']);
    $actor = new CommentActorData('member', 'lifecycle-author');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Version one', metadata: [
            'workflow_event' => 'created',
            'workflow_sequence' => 1,
            'workflow_approved' => false,
            'recipient_user_id' => Str::uuid()->toString(),
        ]),
        $actor,
        CommentAudience::Member,
    );
    $updated = app(UpdateCommentAction::class)->execute(
        $comment,
        new UpdateCommentData(
            body: 'Version two',
            expectedRevision: 1,
            metadata: [
                'workflow_event' => 'updated',
                'workflow_sequence' => 2,
                'workflow_approved' => true,
                'recipient_user_id' => $comment->metadata['recipient_user_id'],
            ],
        ),
        $actor,
        CommentAudience::Member,
    );
    $historical = $updated->revisions()->where('revision', 1)->firstOrFail();
    $revisionProjection = app(CommentProjectionFactory::class)
        ->revision($historical, CommentAudience::Member)
        ->toArray();
    $restored = app(RestoreCommentRevisionAction::class)->execute(
        $updated,
        $historical,
        new RestoreCommentRevisionData(expectedRevision: 2),
        $actor,
        CommentAudience::Member,
    );

    expect($revisionProjection['metadata'])->toBe([[
        'namespace' => 'workflow',
        'values' => ['event' => 'created', 'approved' => false],
    ]])
        ->and(app(FindLatestTargetCommentAction::class)->execute(
            $target,
            $actor,
            new CommentSelectorData(metadataEquals: ['workflow.event' => 'created']),
            CommentAudience::Member,
        )?->id)->toBe($restored->id)
        ->and(app(FindLatestTargetCommentAction::class)->execute(
            $target,
            $actor,
            new CommentSelectorData(metadataEquals: ['workflow.event' => 'updated']),
            CommentAudience::Member,
        ))->toBeNull();

    app(AnonymizeCommentAction::class)->execute(
        $restored,
        new AnonymizeCommentData(expectedRevision: 3, reason: 'Privacy request'),
        CommentActorData::system(),
        CommentAudience::Management,
    );

    expect(CommentMetadataValue::query()->where('comment_id', $comment->id)->exists())
        ->toBeFalse();
});

it('reports and repairs metadata index drift without displaying values', function (): void {
    commentsMetadataConfigure([TestCommentMetadataSchema::class]);
    $target = TestCommentTarget::query()->create(['name' => 'Metadata reconciliation']);
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Reconciled metadata', metadata: [
            'workflow_event' => 'private-reconciliation-value',
        ]),
        new CommentActorData('member', 'reconciliation-author'),
        CommentAudience::Member,
    );
    CommentMetadataValue::query()->where('comment_id', $comment->id)->delete();

    $dryRunExit = Artisan::call('nvl:comments:reconcile', [
        '--format' => 'json',
        '--target' => "article:{$target->id}",
    ]);
    $dryRunOutput = Artisan::output();
    $dryRun = json_decode($dryRunOutput, true, flags: JSON_THROW_ON_ERROR);

    expect($dryRunExit)->toBe(0)
        ->and($dryRun['missingMetadataIndexValues'])->toBe(1)
        ->and($dryRun['staleMetadataIndexValues'])->toBe(0)
        ->and($dryRunOutput)->not->toContain('private-reconciliation-value');

    expect(Artisan::call('nvl:comments:reconcile', [
        '--format' => 'json',
        '--target' => "article:{$target->id}",
        '--repair' => true,
    ]))->toBe(0)
        ->and(CommentMetadataValue::query()->where('comment_id', $comment->id)->count())
        ->toBe(1);
});
