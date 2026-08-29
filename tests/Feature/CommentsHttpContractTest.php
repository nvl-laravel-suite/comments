<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Nvl\Comments\Actions\CreateCommentAction;
use Nvl\Comments\Actions\ReportCommentAction;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Data\Mutations\ReportCommentData;
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentMentionResourceRegistry;
use Nvl\Comments\Services\CommentTargetRegistry;
use Nvl\Comments\Tests\Fixtures\TestCommentMentionResourceResolver;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Comments\Tests\Fixtures\TestStringCommentTarget;
use Nvl\Comments\Tests\Fixtures\TestStringCommentTargetResolver;

beforeEach(function (): void {
    config()->set([
        'comments.routes.public.enabled' => true,
        'comments.routes.member.enabled' => true,
        'comments.routes.management.enabled' => true,
    ]);

    require dirname(__DIR__, 2).'/routes/api.php';

    Route::getRoutes()->refreshNameLookups();
});

/**
 * Create a request-authenticated actor without adding an application user fixture.
 */
function commentsHttpUser(string $id): GenericUser
{
    return new GenericUser(['id' => $id]);
}

/**
 * Assert the audience-specific denial shape used by private Comments routes.
 */
function commentsHttpAssertDenied(TestResponse $response, string $audience): void
{
    if ($audience === 'member') {
        $response->assertNotFound();

        return;
    }

    $response->assertForbidden();
}

it('exposes authenticated rich mutation and suggestion seams without a public suggestion route', function (): void {
    config()->set('comments.mentions.enabled', true);
    app(CommentMentionResourceRegistry::class)->register(
        'organization',
        TestCommentMentionResourceResolver::class,
    );
    $target = TestCommentTarget::query()->create(['name' => 'Rich HTTP']);
    $author = commentsHttpUser('rich-http-author');
    $token = '018f91ba-d58a-73f8-baa7-6b2ed792d865';

    $this->actingAs($author)->getJson(route(
        'nvl.comments.member.mentions.suggestions',
        [
            'target' => 'article',
            'targetId' => $target->id,
            'resource' => 'organization',
            'q' => 'organization',
        ],
    ))->assertOk()
        ->assertJsonPath('data.0.id', 'org-2')
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

    $created = $this->actingAs($author)->postJson(route(
        'nvl.comments.member.rich.store',
        ['target' => 'article', 'targetId' => $target->id],
    ), [
        'document' => [
            'version' => 1,
            'blocks' => [[
                'type' => 'paragraph',
                'children' => [[
                    'type' => 'mention',
                    'tokenId' => $token,
                    'resource' => 'organization',
                    'id' => 'org-1',
                ]],
            ]],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.document.blocks.0.children.0.label', 'Организация')
        ->assertJsonPath('data.mentions.0.resourceId', 'org-1');
    $commentId = $created->json('data.id');

    $this->actingAs($author)->patchJson(route(
        'nvl.comments.member.rich.update',
        ['comment' => $commentId],
    ), [
        'document' => [
            'version' => 1,
            'blocks' => [[
                'type' => 'paragraph',
                'children' => [[
                    'type' => 'mention',
                    'tokenId' => $token,
                    'resource' => 'organization',
                    'id' => 'org-2',
                ]],
            ]],
        ],
        'expectedRevision' => 1,
    ])->assertOk()
        ->assertJsonPath('data.revision', 2)
        ->assertJsonPath('data.mentions.0.resourceId', 'org-2');

    expect(Route::has('nvl.comments.public.mentions.suggestions'))->toBeFalse();
});

it('authorizes private rich HTTP seams before malformed documents and suggestion input', function (
    string $audience,
): void {
    config()->set('comments.mentions.enabled', true);
    app(CommentMentionResourceRegistry::class)->register(
        'organization',
        TestCommentMentionResourceResolver::class,
    );
    $target = TestCommentTarget::query()->create(['name' => "Denied {$audience} rich HTTP"]);
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Existing comment'),
        new CommentActorData('member', 'existing-author'),
    );
    $boundary = new class implements CommentAuthorization, CommentQueryScope
    {
        /**
         * Deny every ability so transport validation cannot disclose target existence.
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
            return false;
        }

        /**
         * Leave test queries visible so explicit authorization owns the denial response.
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
    $user = commentsHttpUser("denied-{$audience}");
    $prefix = "nvl.comments.{$audience}";

    $store = $this->actingAs($user)->postJson(route(
        "{$prefix}.rich.store",
        ['target' => 'article', 'targetId' => $target->id],
    ), ['document' => 'malformed']);
    commentsHttpAssertDenied($store, $audience);

    $update = $this->actingAs($user)->patchJson(route(
        "{$prefix}.rich.update",
        ['comment' => $comment->id],
    ), [
        'document' => 'malformed',
        'expectedRevision' => 'malformed',
    ]);
    commentsHttpAssertDenied($update, $audience);

    $missingQuery = $this->actingAs($user)->getJson(route(
        "{$prefix}.mentions.suggestions",
        [
            'target' => 'article',
            'targetId' => $target->id,
            'resource' => 'unregistered-resource',
        ],
    ));
    commentsHttpAssertDenied($missingQuery, $audience);

    $invalidLimit = $this->actingAs($user)->getJson(route(
        "{$prefix}.mentions.suggestions",
        [
            'target' => 'article',
            'targetId' => $target->id,
            'resource' => 'organization',
            'q' => 'organization',
            'limit' => 21,
        ],
    ));
    commentsHttpAssertDenied($invalidLimit, $audience);

    $unregisteredResource = $this->actingAs($user)->getJson(route(
        "{$prefix}.mentions.suggestions",
        [
            'target' => 'article',
            'targetId' => $target->id,
            'resource' => 'unregistered-resource',
            'q' => 'organization',
        ],
    ));
    commentsHttpAssertDenied($unregisteredResource, $audience);
})->with(['member', 'management']);

it('retains Action reauthorization after private suggestion preauthorization', function (
    string $audience,
): void {
    config()->set('comments.mentions.enabled', true);
    app(CommentMentionResourceRegistry::class)->register(
        'organization',
        TestCommentMentionResourceResolver::class,
    );
    $target = TestCommentTarget::query()->create(['name' => "Reauthorization {$audience}"]);
    $boundary = new class implements CommentAuthorization, CommentQueryScope
    {
        public int $listAuthorizations = 0;

        /**
         * Permit only the HTTP preflight and deny the Action-level list authorization.
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
            if ($ability !== CommentAbility::List) {
                return true;
            }

            $this->listAuthorizations++;

            return $this->listAuthorizations === 1;
        }

        /**
         * Keep comment query scoping inert for suggestion authorization coverage.
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

    $response = $this->actingAs(commentsHttpUser("reauthorize-{$audience}"))->getJson(route(
        "nvl.comments.{$audience}.mentions.suggestions",
        [
            'target' => 'article',
            'targetId' => $target->id,
            'resource' => 'organization',
            'q' => 'organization',
        ],
    ));

    commentsHttpAssertDenied($response, $audience);
    expect($boundary->listAuthorizations)->toBe(2);
})->with(['member', 'management']);

it('creates a public comment with a revision and no private actor or metadata fields', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $author = commentsHttpUser('private-author-id');

    $response = $this->actingAs($author)->postJson(route(
        'nvl.comments.public.store',
        ['target' => 'article', 'targetId' => $target->id],
    ), [
        'body' => 'Public contract',
        'format' => 'markdown',
        'visibility' => 'public',
        'locale' => 'en',
        'tags' => ['contract'],
        'metadata' => ['source' => 'private-metadata-value'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.body', 'Public contract')
        ->assertJsonPath('data.revision', 1)
        ->assertJsonMissingPath('data.actorType')
        ->assertJsonMissingPath('data.actorId')
        ->assertJsonMissingPath('data.metadata');

    expect($response->getContent())
        ->not->toContain('private-author-id')
        ->not->toContain('private-metadata-value');
});

it('preserves omitted editable fields during a body-only public patch', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $author = commentsHttpUser('author-42');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData(
            body: 'Original',
            format: CommentFormat::Markdown,
            locale: 'en-GB',
            tags: ['release', 'guide'],
            metadata: ['source' => 'import'],
        ),
        CommentActorData::fromAuthenticatable($author),
    );

    $response = $this->actingAs($author)->patchJson(route(
        'nvl.comments.public.update',
        ['comment' => $comment->id],
    ), [
        'body' => 'Body-only patch',
        'expectedRevision' => $comment->revision,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.body', 'Body-only patch')
        ->assertJsonPath('data.format', 'markdown')
        ->assertJsonPath('data.locale', 'en-GB')
        ->assertJsonPath('data.tags', ['release', 'guide'])
        ->assertJsonPath('data.revision', 2)
        ->assertJsonMissingPath('data.metadata');

    $persisted = Comment::query()->findOrFail($comment->id);

    expect($persisted->format)->toBe(CommentFormat::Markdown)
        ->and($persisted->locale)->toBe('en-GB')
        ->and($persisted->tags)->toBe(['release', 'guide'])
        ->and($persisted->metadata)->toBe(['source' => 'import']);
});

it('returns a stable conflict response for a stale public patch', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $author = commentsHttpUser('author-42');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Original'),
        CommentActorData::fromAuthenticatable($author),
    );

    $this->actingAs($author)->patchJson(route(
        'nvl.comments.public.update',
        ['comment' => $comment->id],
    ), [
        'body' => 'Stale patch',
        'expectedRevision' => $comment->revision + 1,
    ])->assertConflict()
        ->assertJsonPath('code', 'stale_comment');

    expect($comment->refresh()->body)->toBe('Original')
        ->and($comment->revision)->toBe(1);
});

it('returns a stable not-found response for an unknown target', function (): void {
    $this->actingAs(commentsHttpUser('author-42'))->postJson(route(
        'nvl.comments.public.store',
        [
            'target' => 'article',
            'targetId' => '00000000-0000-0000-0000-000000000000',
        ],
    ), [
        'body' => 'Missing target',
        'format' => 'plain',
        'visibility' => 'public',
    ])->assertNotFound()
        ->assertJsonPath('code', 'comment_target_not_found');
});

it('accepts route-safe domain target identifiers up to the persistence limit', function (
    string $targetId,
): void {
    Schema::create('comment_string_targets', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->timestamps();
    });

    app(CommentTargetRegistry::class)->register(
        'string-article',
        TestStringCommentTargetResolver::class,
    );

    TestStringCommentTarget::query()->create([
        'id' => $targetId,
        'name' => 'String article',
    ]);

    $url = route('nvl.comments.public.store', [
        'target' => 'string-article',
        'targetId' => $targetId,
    ]);

    expect($url)->toContain('/'.rawurlencode($targetId));

    $this->actingAs(commentsHttpUser('author-42'))->postJson($url, [
        'body' => 'Domain identifier route contract',
        'format' => 'plain',
        'visibility' => 'public',
    ])->assertCreated();

    $this->assertDatabaseHas(CommentsTables::Comments, [
        'commentable_id' => $targetId,
        'body' => 'Domain identifier route contract',
    ]);
})->with([
    'URL-encoded UTF-8 and space' => ['Δοκιμή target'],
    '255-character domain identifier' => [str_repeat('a', 255)],
]);

it('rejects associative tags through public transport validation', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);

    $this->actingAs(commentsHttpUser('author-42'))->postJson(route(
        'nvl.comments.public.store',
        ['target' => 'article', 'targetId' => $target->id],
    ), [
        'body' => 'Invalid tags',
        'format' => 'plain',
        'visibility' => 'public',
        'tags' => ['topic' => 'laravel'],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['tags']);

    expect(Comment::query()->count())->toBe(0);
});

it('returns JSON validation errors without requiring an Accept header', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);

    $response = $this->actingAs(commentsHttpUser('author-42'))->post(route(
        'nvl.comments.public.store',
        ['target' => 'article', 'targetId' => $target->id],
    ), [
        'body' => '',
    ]);

    $response->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertJsonValidationErrors(['body']);
});

it('accepts the camel-case optimistic lock token when deleting a public comment', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $author = commentsHttpUser('author-42');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Delete me'),
        CommentActorData::fromAuthenticatable($author),
    );

    $this->actingAs($author)->deleteJson(route(
        'nvl.comments.public.destroy',
        ['comment' => $comment->id],
    ), [
        'expectedRevision' => $comment->revision,
    ])->assertOk()
        ->assertJsonPath('data.id', $comment->id)
        ->assertJsonMissingPath('data.body')
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

    $this->assertSoftDeleted($comment);
});

it('keeps management listings target-scoped and exposes reports for resolution', function (): void {
    $boundary = new class implements CommentAuthorization, CommentQueryScope
    {
        /**
         * Permit every operation for management transport coverage.
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
         * Keep management fixtures target-bound without adding role constraints.
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

    $firstTarget = TestCommentTarget::query()->create(['name' => 'First article']);
    $secondTarget = TestCommentTarget::query()->create(['name' => 'Second article']);
    $create = app(CreateCommentAction::class);
    $firstComment = $create->execute(
        $firstTarget,
        new CreateCommentData('First target comment'),
        new CommentActorData('member', '42'),
    );
    $secondComment = $create->execute(
        $secondTarget,
        new CreateCommentData('Second target comment'),
        new CommentActorData('member', '84'),
    );
    $report = app(ReportCommentAction::class)->execute(
        $firstComment,
        new ReportCommentData('abuse', 'Private moderator details'),
        new CommentActorData('member', 'reporter-1'),
    );
    $moderator = commentsHttpUser('moderator-1');

    $listing = $this->actingAs($moderator)->getJson(route(
        'nvl.comments.management.index',
        ['target' => 'article', 'targetId' => $firstTarget->id],
    ));

    $listing->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $firstComment->id)
        ->assertJsonPath('meta.total', 1)
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertJsonMissing(['id' => $secondComment->id]);

    $reports = $this->getJson(route(
        'nvl.comments.management.reports.index',
        ['comment' => $firstComment->id],
    ));

    $reports->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $report->id)
        ->assertJsonPath('data.0.commentId', $firstComment->id)
        ->assertJsonPath('data.0.reason', 'abuse')
        ->assertJsonPath('data.0.details', 'Private moderator details');

    $this->putJson(route(
        'nvl.comments.management.reports.resolve',
        ['report' => $report->id],
    ), [
        'status' => CommentReportStatus::Resolved->value,
        'expectedRevision' => $firstComment->refresh()->revision,
        'resolution' => 'Reviewed through management HTTP',
    ])->assertOk()
        ->assertJsonPath('data.id', $report->id)
        ->assertJsonPath('data.status', CommentReportStatus::Resolved->value)
        ->assertJsonStructure(['data' => ['reviewedAt']]);

    expect($report->refresh()->status)->toBe(CommentReportStatus::Resolved)
        ->and($report->resolution)->toBe('Reviewed through management HTTP');
});
