<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Nvl\Comments\Actions\AttachCommentMediaAction;
use Nvl\Comments\Actions\CreateCommentAction;
use Nvl\Comments\Actions\ListCommentsAction;
use Nvl\Comments\Actions\ModerateCommentAction;
use Nvl\Comments\Actions\ReportCommentAction;
use Nvl\Comments\Actions\ResolveCommentReportAction;
use Nvl\Comments\Actions\SetCommentReactionAction;
use Nvl\Comments\Actions\UpdateCommentAction;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Data\Mutations\ModerateCommentData;
use Nvl\Comments\Data\Mutations\ReportCommentData;
use Nvl\Comments\Data\Mutations\ResolveCommentReportData;
use Nvl\Comments\Data\Mutations\UpdateCommentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Exceptions\StaleCommentException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Providers\CommentsServiceProvider;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Comments\Tests\Fixtures\TestConfiguredCommentTarget;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media;

it('installs the complete schema with both route groups disabled', function (): void {
    $commentIndexes = Schema::getIndexListing('comments');
    $reportIndexes = Schema::getIndexListing('comment_reports');

    expect(Schema::hasTable('comments'))->toBeTrue()
        ->and(Schema::hasTable('comment_reactions'))->toBeTrue()
        ->and(Schema::hasTable('comment_revisions'))->toBeTrue()
        ->and(Schema::hasTable('comment_reports'))->toBeTrue()
        ->and($commentIndexes)->toContain(
            'comments_target_visibility_idx',
            'comments_thread_order_idx',
            'comments_parent_order_idx',
            'comments_moderation_idx',
        )
        ->and($reportIndexes)->toContain('comment_reports_comment_idx')
        ->and(Route::has('nvl.comments.public.index'))->toBeFalse()
        ->and(Route::has('nvl.comments.management.index'))->toBeFalse();

    $this->artisan('nvl:comments:doctor', ['--strict' => true, '--format' => 'json'])
        ->assertSuccessful()
        ->expectsOutputToContain('"healthy": true');
});

it('rejects non-string target identities before persisting model fingerprints', function (): void {
    expect(fn () => Comment::query()->create([
        'commentable_type' => TestCommentTarget::class,
        'commentable_id' => ['not', 'a', 'key'],
        'body' => 'Invalid direct persistence',
    ]))->toThrow(
        LogicException::class,
        'require string target type and identifier values',
    );
});

it('creates replies preserves revisions and toggles reactions idempotently', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $actor = new CommentActorData('member', '42');
    $root = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('First'),
        $actor,
    );
    $reply = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Reply', parentId: $root->id),
        $actor,
    );
    $updated = app(UpdateCommentAction::class)->execute(
        $root,
        new UpdateCommentData('Edited', $root->revision),
        $actor,
    );
    $reaction = app(SetCommentReactionAction::class)->execute(
        $updated,
        'helpful',
        true,
        $actor,
    );
    $same = app(SetCommentReactionAction::class)->execute(
        $updated,
        'helpful',
        true,
        $actor,
    );
    $comments = app(ListCommentsAction::class)->execute(
        $target,
        CommentActorData::anonymous(),
        FilterSet::none(),
    );

    expect($reply->root_id)->toBe($root->id)
        ->and($reply->depth)->toBe(1)
        ->and($updated->revisions()->count())->toBe(1)
        ->and($reaction?->id)->toBe($same?->id)
        ->and($comments->total())->toBe(2);
});

it('rejects stale edits and protects non-public listings', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $author = new CommentActorData('member', '42');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Private', visibility: CommentVisibility::Private),
        $author,
        CommentAudience::Member,
    );
    $updated = app(UpdateCommentAction::class)->execute(
        $comment,
        new UpdateCommentData('Private edit', $comment->revision),
        $author,
        CommentAudience::Member,
    );

    expect(fn () => app(UpdateCommentAction::class)->execute(
        $updated,
        new UpdateCommentData('Stale edit', 1),
        $author,
        CommentAudience::Member,
    ))->toThrow(StaleCommentException::class);

    expect(fn () => app(ListCommentsAction::class)->execute(
        $target,
        CommentActorData::anonymous(),
        FilterSet::none(),
        audience: CommentAudience::Member,
    ))->toThrow(AuthorizationException::class);
});

it('reports and moderates comments without inflating counters', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Needs review'),
        new CommentActorData('member', '42'),
    );
    $reporter = new CommentActorData('member', '84');
    $report = app(ReportCommentAction::class)->execute(
        $comment,
        new ReportCommentData('spam', 'Suspicious links'),
        $reporter,
    );
    $same = app(ReportCommentAction::class)->execute(
        $comment,
        new ReportCommentData('spam', 'Updated details'),
        $reporter,
    );
    $resolved = app(ResolveCommentReportAction::class)->execute(
        $same,
        new ResolveCommentReportData(
            CommentReportStatus::Resolved,
            $comment->refresh()->revision,
            'Confirmed and moderated',
        ),
        CommentActorData::system(),
    );
    $moderated = app(ModerateCommentAction::class)->execute(
        $comment->refresh(),
        new ModerateCommentData(
            CommentStatus::Hidden,
            $comment->refresh()->revision,
            reason: 'Spam',
        ),
        CommentActorData::system(),
    );

    expect($report->id)->toBe($same->id)
        ->and($comment->refresh()->report_count)->toBe(1)
        ->and($resolved->status)->toBe(CommentReportStatus::Resolved)
        ->and($resolved->reviewed_by_type)->toBe('system')
        ->and($moderated->status)->toBe(CommentStatus::Hidden)
        ->and($moderated->revision)->toBe(3);
});

it('enforces private attachment uploader ownership and remains idempotent', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $author = new CommentActorData('member', '42');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('With an attachment'),
        $author,
    );
    $ownedMedia = Media::factory()->create([
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => 'member',
        'uploaded_by' => '42',
    ]);
    $association = app(AttachCommentMediaAction::class)->execute(
        $comment,
        $ownedMedia,
        $author,
    );
    $same = app(AttachCommentMediaAction::class)->execute(
        $comment,
        $ownedMedia,
        $author,
    );

    expect($association->id)->toBe($same->id)
        ->and($comment->media()->count())->toBe(1);

    $foreignMedia = Media::factory()->create([
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => 'member',
        'uploaded_by' => '84',
    ]);

    expect(fn () => app(AttachCommentMediaAction::class)->execute(
        $comment,
        $foreignMedia,
        $author,
    ))->toThrow(AuthorizationException::class);

    $publicMedia = Media::factory()->create([
        'is_public' => true,
        'visibility' => MediaVisibility::Public,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => 'member',
        'uploaded_by' => '42',
    ]);

    expect(fn () => app(AttachCommentMediaAction::class)->execute(
        $comment,
        $publicMedia,
        $author,
    ))->toThrow(InvalidArgumentException::class);
});

it('enforces bounded content and thread depth', function (): void {
    config()->set('comments.threading.maximum_depth', 1);
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $actor = new CommentActorData('member', '42');
    $root = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Root'),
        $actor,
    );
    $reply = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Reply', parentId: $root->id),
        $actor,
    );

    expect(fn () => app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Too deep', parentId: $reply->id),
        $actor,
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('   '),
        $actor,
    ))->toThrow(InvalidArgumentException::class);
});

it('authorizes replies against their parent and inherits its visibility', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $author = new CommentActorData('member', '42');
    $privateRoot = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Private root', visibility: CommentVisibility::Private),
        $author,
        CommentAudience::Member,
    );

    expect(fn () => app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Leaked reply', parentId: $privateRoot->id),
        new CommentActorData('member', '84'),
        CommentAudience::Member,
    ))->toThrow(ModelNotFoundException::class);

    $privateReply = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Internal reply', parentId: $privateRoot->id),
        CommentActorData::system(),
        CommentAudience::Member,
    );

    config()->set('comments.moderation.new_status', CommentStatus::Pending->value);
    $pendingRoot = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Pending root'),
        $author,
    );

    expect($privateReply->visibility)->toBe(CommentVisibility::Private)
        ->and(fn () => app(CreateCommentAction::class)->execute(
            $target,
            new CreateCommentData('Premature reply', parentId: $pendingRoot->id),
            $author,
        ))->toThrow(AuthorizationException::class);
});

it('rolls back comment mutations on the configured database connection', function (): void {
    config()->set('database.connections.comments', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    $defaultConnection = config('database.default');
    config()->set('database.default', 'comments');
    config()->set('comments.connection', null);

    foreach (glob(__DIR__.'/../../database/migrations/*.php') ?: [] as $migrationPath) {
        (require $migrationPath)->up();
    }

    config()->set('database.default', $defaultConnection);
    config()->set('comments.connection', 'comments');

    Schema::connection('comments')->create('comment_configured_targets', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $target = TestConfiguredCommentTarget::query()->create(['name' => 'Article']);
    $actor = new CommentActorData('member', '42');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Original'),
        $actor,
    );
    $lazyTarget = $comment->commentable()->first();
    $eagerComment = Comment::query()
        ->with('commentable')
        ->findOrFail($comment->id);

    $media = Media::factory()->create([
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => 'member',
        'uploaded_by' => '42',
    ]);

    expect(fn () => app(AttachCommentMediaAction::class)->execute(
        $comment,
        $media,
        $actor,
    ))->toThrow(
        InvalidArgumentException::class,
        'Comment attachments require Comments and Media to use the same database connection.',
    );
    expect($lazyTarget?->getKey())->toBe($target->id)
        ->and($eagerComment->commentable?->getKey())->toBe($target->id);

    Event::listen(
        'eloquent.created: '.CommentRevision::class,
        static function (): void {
            throw new RuntimeException('Force the mutation to roll back.');
        },
    );

    expect(fn () => app(UpdateCommentAction::class)->execute(
        $comment,
        new UpdateCommentData('Changed', $comment->revision),
        $actor,
    ))->toThrow(RuntimeException::class, 'Force the mutation to roll back.');

    expect(Comment::query()->findOrFail($comment->id)->body)->toBe('Original')
        ->and(Comment::query()->findOrFail($comment->id)->revision)->toBe(1)
        ->and(CommentRevision::query()->count())->toBe(0);
});

it('supports cross-connection target reads and rejects impossible SQL joins and uncommitted creation', function (): void {
    config()->set('database.connections.comments_cross', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    $defaultConnection = config('database.default');
    config()->set('database.default', 'comments_cross');
    config()->set('comments.connection', null);

    foreach (glob(__DIR__.'/../../database/migrations/*.php') ?: [] as $migrationPath) {
        (require $migrationPath)->up();
    }

    config()->set('database.default', $defaultConnection);
    config()->set('comments.connection', 'comments_cross');

    $target = TestCommentTarget::query()->create(['name' => 'Cross connection']);
    $comment = Comment::query()->create([
        'commentable_type' => $target->getMorphClass(),
        'commentable_id' => (string) $target->getKey(),
        'actor_type' => 'member',
        'actor_id' => '42',
        'body' => 'Stored away from the target',
    ]);

    $eagerTarget = TestCommentTarget::query()
        ->with('comments')
        ->findOrFail($target->getKey());
    $eagerComment = Comment::query()
        ->with('commentable')
        ->findOrFail($comment->id);

    expect($target->comments()->sole()->is($comment))->toBeTrue()
        ->and($eagerTarget->comments->sole()->is($comment))->toBeTrue()
        ->and($eagerComment->commentable?->is($target))->toBeTrue()
        ->and(fn () => TestCommentTarget::query()->whereHas('comments')->get())
        ->toThrow(LogicException::class, 'require targets and Comments to share one database connection')
        ->and(fn () => app(CreateCommentAction::class)->execute(
            $target,
            new CreateCommentData('Target transaction is not committed'),
            new CommentActorData('member', '84'),
        ))->toThrow(
            InvalidCommentMutationException::class,
            'must run after the target transaction commits',
        );
});

it('fails migration collisions without replacing a consumer table', function (): void {
    config()->set('database.connections.comments_collision', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    $defaultConnection = config('database.default');
    config()->set('database.default', 'comments_collision');
    config()->set('comments.connection', null);

    Schema::connection('comments_collision')->create('comments', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('consumer_marker');
    });

    $migration = require __DIR__
        .'/../../database/migrations/2026_07_27_110001_create_comments_table.php';

    expect(fn () => $migration->up())->toThrow(QueryException::class)
        ->and(Schema::connection('comments_collision')->hasColumns(
            'comments',
            ['id', 'consumer_marker'],
        ))->toBeTrue();

    config()->set('database.default', $defaultConnection);
});

it('refuses mutable custom targets in package-managed migrations', function (): void {
    config()->set('database.connections.comments_custom', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('comments.connection', 'comments_custom');
    $migration = require __DIR__
        .'/../../database/migrations/2026_07_27_110001_create_comments_table.php';

    expect(fn () => $migration->up())->toThrow(
        LogicException::class,
        'application-owned migrations',
    )->and(Schema::connection('comments_custom')->hasTable('comments'))->toBeFalse();
});

it('replaces consumer configuration lists atomically while retaining nested defaults', function (): void {
    config()->set('comments.content.allowed_formats', ['markdown']);
    config()->set('comments.reactions.allowed', []);
    config()->set('comments.moderation.actionable_statuses', ['spam']);

    (new CommentsServiceProvider(app()))->register();

    expect(config('comments.content.allowed_formats'))->toBe(['markdown'])
        ->and(config('comments.reactions.allowed'))->toBe([])
        ->and(config('comments.moderation.actionable_statuses'))->toBe(['spam'])
        ->and(config('comments.content.maximum_bytes'))->toBe(20_000)
        ->and(config('comments.routes.public.prefix'))->toBe('api/v1/discussions');
});

it('rejects a non-array comments configuration root', function (): void {
    config()->set('comments', 'invalid');

    expect(fn () => (new CommentsServiceProvider(app()))->register())
        ->toThrow(RuntimeException::class, 'must contain an array');
});
