<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Comments\Actions\AnonymizeCommentAction;
use Nvl\Comments\Actions\AttachCommentMediaAction;
use Nvl\Comments\Actions\CreateCommentAction;
use Nvl\Comments\Actions\DeleteCommentAction;
use Nvl\Comments\Actions\DetachCommentMediaAction;
use Nvl\Comments\Actions\FindLatestTargetCommentAction;
use Nvl\Comments\Actions\ListCommentAttachmentsAction;
use Nvl\Comments\Actions\ListCommentRevisionsAction;
use Nvl\Comments\Actions\ModerateCommentAction;
use Nvl\Comments\Actions\ReportCommentAction;
use Nvl\Comments\Actions\ResolveCommentReportAction;
use Nvl\Comments\Actions\RestoreCommentAction;
use Nvl\Comments\Actions\RestoreCommentRevisionAction;
use Nvl\Comments\Actions\SetCommentReactionAction;
use Nvl\Comments\Actions\UpdateCommentAction;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentAuthorPresenter;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentAuthorData;
use Nvl\Comments\Data\CommentManagementData;
use Nvl\Comments\Data\CommentReportManagementData;
use Nvl\Comments\Data\MemberCommentData;
use Nvl\Comments\Data\Mutations\AnonymizeCommentData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Data\Mutations\DeleteCommentData;
use Nvl\Comments\Data\Mutations\ModerateCommentData;
use Nvl\Comments\Data\Mutations\ReportCommentData;
use Nvl\Comments\Data\Mutations\ResolveCommentReportData;
use Nvl\Comments\Data\Mutations\RestoreCommentData;
use Nvl\Comments\Data\Mutations\RestoreCommentRevisionData;
use Nvl\Comments\Data\Mutations\UpdateCommentData;
use Nvl\Comments\Data\PublicCommentData;
use Nvl\Comments\Data\Queries\CommentSelectorData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentAttachmentUrlFactory;
use Nvl\Comments\Services\CommentMetadataRegistry;
use Nvl\Comments\Services\CommentProjectionFactory;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\ConfiguredCommentAuthorization;
use Nvl\Comments\Tests\Fixtures\TestCommentMetadataSchema;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Translatable\Services\ContentLocale;

/**
 * Register only the requested Comments HTTP surfaces for the current test app.
 */
function commentsV1RegisterRoutes(
    bool $public = false,
    bool $member = false,
    bool $management = false,
): void {
    config()->set([
        'comments.routes.public.enabled' => $public,
        'comments.routes.member.enabled' => $member,
        'comments.routes.management.enabled' => $management,
    ]);

    require dirname(__DIR__, 2).'/routes/api.php';

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
}

/**
 * Create a request-authenticated member without an application user fixture.
 */
function commentsV1Member(string $id): GenericUser
{
    return new GenericUser(['id' => $id]);
}

/**
 * Bind an intentionally permissive management boundary for transport tests.
 */
function commentsV1BindManagementBoundary(): void
{
    $boundary = new class implements CommentAuthorization, CommentQueryScope
    {
        /**
         * Permit the privileged API operations exercised by this isolated suite.
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
         * Preserve only the package's canonical target constraint.
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
}

it('finds the latest target comment through bounded selectors and audience projections', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Latest comment target']);
    $actor = new CommentActorData('member', 'latest-comment-viewer');
    $create = app(CreateCommentAction::class);
    $older = $create->execute(
        $target,
        new CreateCommentData(
            body: 'Older matching comment',
            tags: ['workflow', 'decision'],
        ),
        $actor,
    );
    $newer = $create->execute(
        $target,
        new CreateCommentData(
            body: 'Newer matching comment',
            tags: ['workflow', 'decision'],
        ),
        $actor,
    );
    $newestPartialMatch = $create->execute(
        $target,
        new CreateCommentData(
            body: 'Newest partial match',
            tags: ['workflow'],
        ),
        $actor,
    );
    $newestPending = $create->execute(
        $target,
        new CreateCommentData(
            body: 'Newest pending match',
            tags: ['workflow', 'decision'],
        ),
        $actor,
    );
    $newestDeleted = $create->execute(
        $target,
        new CreateCommentData(
            body: 'Newest deleted match',
            tags: ['workflow', 'decision'],
        ),
        $actor,
    );
    $newestPending->forceFill(['status' => CommentStatus::Pending])->save();
    $older->forceFill(['created_at' => now()->subMinutes(2)])->saveQuietly();
    $newer->forceFill(['created_at' => now()->subMinute()])->saveQuietly();
    $newestPartialMatch->forceFill(['created_at' => now()])->saveQuietly();
    $newestPending->forceFill(['created_at' => now()->addMinute()])->saveQuietly();
    $newestDeleted->forceFill(['created_at' => now()->addMinutes(2)])->saveQuietly();
    $newestDeleted->delete();
    $selector = new CommentSelectorData(
        tags: ['workflow', 'decision'],
        status: CommentStatus::Approved,
    );

    $public = app(FindLatestTargetCommentAction::class)->execute(
        $target,
        CommentActorData::anonymous(),
        $selector,
        CommentAudience::Public,
    );
    $member = app(FindLatestTargetCommentAction::class)->execute(
        $target,
        $actor,
        $selector,
    );
    commentsV1BindManagementBoundary();
    $management = app(FindLatestTargetCommentAction::class)->execute(
        $target,
        CommentActorData::system(),
        $selector,
        CommentAudience::Management,
    );

    expect($public)->toBeInstanceOf(PublicCommentData::class)
        ->and($public?->id)->toBe($newer->id)
        ->and($member)->toBeInstanceOf(MemberCommentData::class)
        ->and($member?->id)->toBe($newer->id)
        ->and($management)->toBeInstanceOf(CommentManagementData::class)
        ->and($management?->id)->toBe($newer->id)
        ->and(app(FindLatestTargetCommentAction::class)->execute(
            $target,
            CommentActorData::anonymous(),
            new CommentSelectorData(tags: ['missing']),
            CommentAudience::Public,
        ))->toBeNull();
});

it('returns only audience-registered metadata through HTTP projections', function (): void {
    config()->set([
        'comments.metadata.schemas' => [TestCommentMetadataSchema::class],
        'comments.metadata.digest_key' => 'comments-api-metadata-key',
    ]);
    app()->forgetInstance(CommentMetadataRegistry::class);
    commentsV1RegisterRoutes(public: true, member: true);
    $target = TestCommentTarget::query()->create(['name' => 'Metadata API projection']);
    $viewer = commentsV1Member('metadata-api-viewer');
    $actor = CommentActorData::fromAuthenticatable($viewer);
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Metadata API comment', metadata: [
            'legacy_private' => 'legacy-api-secret',
            'workflow_event' => 'submitted',
            'workflow_sequence' => 14,
            'workflow_approved' => true,
        ]),
        $actor,
        CommentAudience::Member,
    );

    $public = $this->getJson(route('nvl.comments.public.show', [
        'comment' => $comment->id,
    ]))->assertOk();
    $member = $this->actingAs($viewer)->getJson(route('nvl.comments.member.show', [
        'comment' => $comment->id,
    ]))->assertOk();

    $public->assertJsonPath('data.metadata.0.namespace', 'workflow')
        ->assertJsonPath('data.metadata.0.values.sequence', 14)
        ->assertJsonMissingPath('data.metadata.0.values.event');
    $member->assertJsonPath('data.metadata.0.namespace', 'workflow')
        ->assertJsonPath('data.metadata.0.values.event', 'submitted')
        ->assertJsonPath('data.metadata.0.values.approved', true)
        ->assertJsonMissingPath('data.metadata.0.values.sequence');

    expect($public->getContent().$member->getContent())
        ->not->toContain('legacy_private', 'legacy-api-secret');
});

it('uses the comment identifier as the deterministic latest-selector tie breaker', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Latest tie target']);
    $actor = new CommentActorData('member', 'latest-tie-viewer');
    $create = app(CreateCommentAction::class);
    $first = $create->execute(
        $target,
        new CreateCommentData('First tied comment', tags: ['tied']),
        $actor,
    );
    $second = $create->execute(
        $target,
        new CreateCommentData('Second tied comment', tags: ['tied']),
        $actor,
    );
    $timestamp = now()->startOfSecond();
    $first->forceFill(['created_at' => $timestamp])->saveQuietly();
    $second->forceFill(['created_at' => $timestamp])->saveQuietly();
    $expectedId = max($first->id, $second->id);

    $result = app(FindLatestTargetCommentAction::class)->execute(
        $target,
        CommentActorData::anonymous(),
        new CommentSelectorData(tags: ['tied']),
        CommentAudience::Public,
    );

    expect($result?->id)->toBe($expectedId);
});

it('validates latest-comment tags and denies management before querying', function (): void {
    expect(fn () => new CommentSelectorData(tags: ['duplicate', 'duplicate']))
        ->toThrow(InvalidArgumentException::class, 'distinct')
        ->and(fn () => new CommentSelectorData(tags: ['']))
        ->toThrow(InvalidArgumentException::class, 'non-blank')
        ->and(fn () => new CommentSelectorData(tags: array_fill(0, 21, 'tag')))
        ->toThrow(InvalidArgumentException::class, 'at most');

    config()->set('comments.content.maximum_tags', 50);
    $tooManyDistinctTags = array_map(
        static fn (int $index): string => "tag-{$index}",
        range(1, 21),
    );
    expect(fn () => new CommentSelectorData(tags: $tooManyDistinctTags))
        ->toThrow(InvalidArgumentException::class, 'at most 20');

    $target = TestCommentTarget::query()->create(['name' => 'Denied latest target']);
    $boundary = new class implements CommentAuthorization, CommentQueryScope
    {
        /** @param  array<string, mixed>  $context */
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

        /** @param  Builder<Comment>  $query */
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
    DB::flushQueryLog();
    DB::enableQueryLog();

    expect(fn () => app(FindLatestTargetCommentAction::class)->execute(
        $target,
        CommentActorData::system(),
        new CommentSelectorData(tags: ['workflow']),
        CommentAudience::Management,
    ))->toThrow(AuthorizationException::class)
        ->and(DB::getQueryLog())->toBeEmpty();
});

it('keeps every route group disabled by default and enables the complete member API independently', function (): void {
    expect(Route::has('nvl.comments.public.index'))->toBeFalse()
        ->and(Route::has('nvl.comments.member.index'))->toBeFalse()
        ->and(Route::has('nvl.comments.management.index'))->toBeFalse();

    commentsV1RegisterRoutes(member: true);

    $memberRouteNames = [
        'nvl.comments.member.index',
        'nvl.comments.member.store',
        'nvl.comments.member.show',
        'nvl.comments.member.update',
        'nvl.comments.member.destroy',
        'nvl.comments.member.restore',
        'nvl.comments.member.reaction',
        'nvl.comments.member.reports.store',
        'nvl.comments.member.attachments.index',
        'nvl.comments.member.attachments.store',
        'nvl.comments.member.attachments.destroy',
        'nvl.comments.member.revisions.index',
        'nvl.comments.member.revisions.restore',
    ];

    expect(array_values(array_filter(
        $memberRouteNames,
        static fn (string $name): bool => ! Route::has($name),
    )))->toBe([])
        ->and(Route::has('nvl.comments.public.index'))->toBeFalse()
        ->and(Route::has('nvl.comments.management.index'))->toBeFalse()
        ->and(Route::getRoutes()->getByName('nvl.comments.member.index')?->uri())
        ->toBe('api/v1/member/discussions/targets/{target}/{targetId}')
        ->and(Route::getRoutes()->getByName('nvl.comments.member.index')?->gatherMiddleware())
        ->toContain('auth', 'throttle:60,1');
});

it('limits the default member expansion to the viewers own pending rejected or private rows', function (): void {
    commentsV1RegisterRoutes(member: true);

    $target = TestCommentTarget::query()->create(['name' => 'Member scope article']);
    $viewer = commentsV1Member('member-scope-viewer');
    $viewerActor = CommentActorData::fromAuthenticatable($viewer);
    $create = app(CreateCommentAction::class);
    $public = $create->execute(
        $target,
        new CreateCommentData('Public row'),
        new CommentActorData('member', 'another-author'),
    );
    $pending = $create->execute(
        $target,
        new CreateCommentData('Own pending row'),
        $viewerActor,
    );
    $pending->forceFill(['status' => CommentStatus::Pending])->save();
    $rejected = $create->execute(
        $target,
        new CreateCommentData('Own rejected row'),
        $viewerActor,
    );
    $rejected->forceFill(['status' => CommentStatus::Rejected])->save();
    $private = $create->execute(
        $target,
        new CreateCommentData(
            'Own private row',
            visibility: CommentVisibility::Private,
        ),
        $viewerActor,
        CommentAudience::Member,
    );
    $hidden = $create->execute(
        $target,
        new CreateCommentData('Own hidden row'),
        $viewerActor,
    );
    $hidden->forceFill(['status' => CommentStatus::Hidden])->save();
    $spam = $create->execute(
        $target,
        new CreateCommentData('Own spam row'),
        $viewerActor,
    );
    $spam->forceFill(['status' => CommentStatus::Spam])->save();
    $internal = $create->execute(
        $target,
        new CreateCommentData('Own internal row'),
        $viewerActor,
    );
    $internal->forceFill(['visibility' => CommentVisibility::Internal])->save();

    $response = $this->actingAs($viewer)
        ->getJson(route('nvl.comments.member.index', [
            'target' => 'article',
            'targetId' => $target->id,
        ]))
        ->assertOk()
        ->assertJsonPath('meta.total', 4);
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($public->id, $pending->id, $rejected->id, $private->id)
        ->not->toContain($hidden->id, $spam->id, $internal->id);

    foreach ([$hidden, $spam, $internal] as $inaccessible) {
        $this->actingAs($viewer)
            ->getJson(route('nvl.comments.member.show', [
                'comment' => $inaccessible->id,
            ]))
            ->assertNotFound();
    }
});

it('applies the complete actor status visibility read matrix before caller queries', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Audience matrix']);
    $viewer = new CommentActorData('member', 'matrix-viewer');
    $rows = [];

    foreach (['own', 'foreign'] as $ownership) {
        foreach (CommentStatus::cases() as $status) {
            foreach (CommentVisibility::cases() as $visibility) {
                $comment = Comment::query()->create([
                    'commentable_type' => $target->getMorphClass(),
                    'commentable_id' => (string) $target->getKey(),
                    'actor_type' => 'member',
                    'actor_id' => $ownership === 'own'
                        ? $viewer->id
                        : 'matrix-foreign',
                    'body' => "{$ownership}-{$status->value}-{$visibility->value}",
                    'status' => $status,
                    'visibility' => $visibility,
                ]);
                $rows[$comment->id] = [$ownership, $status, $visibility];
            }
        }
    }

    $reads = app(CommentReadService::class);
    $publicIds = $reads
        ->query($target, CommentActorData::anonymous(), CommentAudience::Public)
        ->pluck('id')
        ->all();
    $memberIds = $reads
        ->query($target, $viewer, CommentAudience::Member)
        ->pluck('id')
        ->all();

    commentsV1BindManagementBoundary();
    $managementIds = app(CommentReadService::class)
        ->query($target, $viewer, CommentAudience::Management)
        ->pluck('id')
        ->all();

    foreach ($rows as $id => [$ownership, $status, $visibility]) {
        $public = $status === CommentStatus::Approved
            && $visibility === CommentVisibility::Public;
        $member = $public
            || (
                $ownership === 'own'
                && (
                    in_array(
                        $status,
                        [CommentStatus::Pending, CommentStatus::Rejected],
                        true,
                    )
                    || $visibility === CommentVisibility::Private
                )
            );

        expect(in_array($id, $publicIds, true))->toBe($public)
            ->and(in_array($id, $memberIds, true))->toBe($member)
            ->and($managementIds)->toContain($id);
    }
});

it('covers every default ability across actor status visibility and audience roles', function (): void {
    config()->set([
        'comments.anonymous.enabled' => true,
        'comments.moderation.allow_author_delete' => true,
        'comments.moderation.allow_author_restore' => true,
    ]);
    $policy = app(ConfiguredCommentAuthorization::class);
    $actors = [
        'anonymous' => CommentActorData::anonymous(),
        'author' => new CommentActorData('member', 'matrix-author'),
        'other' => new CommentActorData('member', 'matrix-other'),
        'system' => CommentActorData::system(),
    ];

    foreach (CommentStatus::cases() as $status) {
        foreach (CommentVisibility::cases() as $visibility) {
            $comment = new Comment([
                'actor_type' => 'member',
                'actor_id' => 'matrix-author',
                'body' => 'Ability matrix',
                'status' => $status,
                'visibility' => $visibility,
            ]);

            foreach (CommentAudience::cases() as $audience) {
                foreach ($actors as $role => $actor) {
                    $identified = $actor->id !== null;
                    $author = $role === 'author';
                    $publiclyVisible = $status === CommentStatus::Approved
                        && $visibility === CommentVisibility::Public;
                    $ownMemberVisible = $author
                        && (
                            in_array($status, [
                                CommentStatus::Pending,
                                CommentStatus::Rejected,
                            ], true)
                            || $visibility === CommentVisibility::Private
                        );
                    $visible = $publiclyVisible
                        || (
                            $audience === CommentAudience::Member
                            && $ownMemberVisible
                        );
                    $canParticipate = $audience !== CommentAudience::Management
                        && (
                            $identified
                            || $audience === CommentAudience::Public
                        );

                    foreach (CommentAbility::cases() as $ability) {
                        $expected = $actor->system || match ($ability) {
                            CommentAbility::List => $audience === CommentAudience::Public
                                || (
                                    $audience === CommentAudience::Member
                                    && $identified
                                ),
                            CommentAbility::View => $visible,
                            CommentAbility::Create => $canParticipate
                                && match ($audience) {
                                    CommentAudience::Public => $visibility
                                        === CommentVisibility::Public,
                                    CommentAudience::Member => in_array(
                                        $visibility,
                                        [
                                            CommentVisibility::Public,
                                            CommentVisibility::Private,
                                        ],
                                        true,
                                    ),
                                    CommentAudience::Management => false,
                                },
                            CommentAbility::Reply => $canParticipate && $visible,
                            CommentAbility::Update,
                            CommentAbility::Attach,
                            CommentAbility::Detach,
                            CommentAbility::ViewHistory,
                            CommentAbility::RestoreRevision => $author,
                            CommentAbility::Delete => $author,
                            CommentAbility::Restore => false,
                            CommentAbility::React,
                            CommentAbility::Report => $identified && $visible,
                            CommentAbility::Anonymize,
                            CommentAbility::ViewIdentity,
                            CommentAbility::Moderate => false,
                        };
                        $actual = $policy->allows(
                            $ability,
                            $actor,
                            $comment,
                            audience: $audience,
                            context: ['visibility' => $visibility->value],
                        );

                        $this->assertSame(
                            $expected,
                            $actual,
                            implode(' / ', [
                                $role,
                                $status->value,
                                $visibility->value,
                                $audience->value,
                                $ability->value,
                            ]),
                        );
                    }
                }
            }
        }
    }
});

it('separates public member and management reads without leaking private identities or reports', function (): void {
    commentsV1RegisterRoutes(public: true, member: true, management: true);

    $target = TestCommentTarget::query()->create(['name' => 'Audience article']);
    $otherTarget = TestCommentTarget::query()->create(['name' => 'Other article']);
    $viewer = commentsV1Member('viewer-sensitive-id');
    $viewerActor = CommentActorData::fromAuthenticatable($viewer);
    $otherActor = new CommentActorData('member', 'foreign-sensitive-id');
    $create = app(CreateCommentAction::class);
    $publicComment = $create->execute(
        $target,
        new CreateCommentData(
            body: 'Approved public body',
            metadata: ['origin' => 'private-public-metadata'],
        ),
        $otherActor,
    );
    $ownPending = $create->execute(
        $target,
        new CreateCommentData(
            body: 'Viewer pending body',
            visibility: CommentVisibility::Private,
            metadata: ['origin' => 'private-pending-metadata'],
        ),
        $viewerActor,
        CommentAudience::Member,
    );
    $ownPending->forceFill(['status' => CommentStatus::Pending])->save();
    $ownRejected = $create->execute(
        $target,
        new CreateCommentData(
            body: 'Viewer rejected body',
            visibility: CommentVisibility::Private,
        ),
        $viewerActor,
        CommentAudience::Member,
    );
    $ownRejected->forceFill(['status' => CommentStatus::Rejected])->save();
    $foreignPending = $create->execute(
        $target,
        new CreateCommentData('Foreign pending body'),
        $otherActor,
    );
    $foreignPending->forceFill(['status' => CommentStatus::Pending])->save();
    $otherTargetPending = $create->execute(
        $otherTarget,
        new CreateCommentData('Other target pending body'),
        $otherActor,
    );
    $otherTargetPending->forceFill(['status' => CommentStatus::Pending])->save();
    app(ReportCommentAction::class)->execute(
        $ownRejected,
        new ReportCommentData('privacy', 'report-details-must-stay-private'),
        $viewerActor,
        CommentAudience::Member,
    );

    $publicResponse = $this->getJson(route('nvl.comments.public.index', [
        'target' => 'article',
        'targetId' => $target->id,
    ]))->assertOk()
        ->assertJsonPath('meta.total', 1);

    $memberResponse = $this->actingAs($viewer)->getJson(route('nvl.comments.member.index', [
        'target' => 'article',
        'targetId' => $target->id,
    ]))->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

    commentsV1BindManagementBoundary();

    $managementResponse = $this->actingAs(commentsV1Member('moderator-sensitive-id'))
        ->getJson(route('nvl.comments.management.index', [
            'target' => 'article',
            'targetId' => $target->id,
        ]))
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

    $publicPayloads = $publicResponse->json('data');
    $memberPayloads = $memberResponse->json('data');
    $managementPayloads = $managementResponse->json('data');

    expect(collect($publicPayloads)->pluck('id')->all())->toBe([$publicComment->id])
        ->and($publicResponse->headers->get('Cache-Control'))
        ->toContain('public', 'max-age=60', 's-maxage=60')
        ->and(collect($memberPayloads)->pluck('id')->sort()->values()->all())->toBe(collect([
            $publicComment->id,
            $ownPending->id,
            $ownRejected->id,
        ])->sort()->values()->all())
        ->and(collect($managementPayloads)->pluck('id')->sort()->values()->all())->toBe(collect([
            $ownPending->id,
            $ownRejected->id,
            $foreignPending->id,
        ])->sort()->values()->all())
        ->and(collect($managementPayloads)->pluck('id')->all())
        ->not->toContain($otherTargetPending->id);

    foreach ([...$publicPayloads, ...$memberPayloads] as $payload) {
        expect(array_intersect([
            'actorType',
            'actorId',
            'metadata',
            'moderationReason',
            'moderatedByType',
            'moderatedBy',
            'reportCount',
            'openReportCount',
            'reporterType',
            'reporterId',
            'reportDetails',
        ], array_keys($payload)))->toBe([]);
    }

    $memberRejected = collect($memberPayloads)->firstWhere('id', $ownRejected->id);
    $managementRejected = collect($managementPayloads)->firstWhere('id', $ownRejected->id);
    $publicJson = $publicResponse->getContent();
    $memberJson = $memberResponse->getContent();

    expect($memberRejected['status'] ?? null)->toBe(CommentStatus::Rejected->value)
        ->and($memberRejected['visibility'] ?? null)->toBe(CommentVisibility::Private->value)
        ->and($memberRejected['isAuthor'] ?? null)->toBeTrue()
        ->and($managementRejected['reportCount'] ?? null)->toBe(1)
        ->and($managementRejected['openReportCount'] ?? null)->toBe(1)
        ->and($managementRejected['actorId'] ?? null)->toBe('viewer-sensitive-id')
        ->and($publicJson)->not->toContain('foreign-sensitive-id')
        ->and($publicJson)->not->toContain('private-public-metadata')
        ->and($memberJson)->not->toContain('viewer-sensitive-id')
        ->and($memberJson)->not->toContain('foreign-sensitive-id')
        ->and($memberJson)->not->toContain('private-pending-metadata')
        ->and($memberJson)->not->toContain('report-details-must-stay-private');
});

it('requires identity-view permission independently from moderation permission', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Identity authorization']);
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Identity-protected comment'),
        new CommentActorData('member', 'sensitive-author-id'),
    );
    $report = app(ReportCommentAction::class)->execute(
        $comment,
        new ReportCommentData('privacy'),
        new CommentActorData('member', 'sensitive-reporter-id'),
    );
    $moderator = new CommentActorData('member', 'moderator');
    $defaultPolicy = app(ConfiguredCommentAuthorization::class);

    expect($defaultPolicy->allows(
        CommentAbility::ViewIdentity,
        $moderator,
        $comment,
        $target,
        CommentAudience::Management,
    ))->toBeFalse();

    $boundary = new class implements CommentAuthorization, CommentQueryScope
    {
        public bool $allowIdentity = false;

        /**
         * Grant moderation while independently controlling raw identity access.
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
            return $ability === CommentAbility::Moderate
                || ($ability === CommentAbility::ViewIdentity && $this->allowIdentity);
        }

        /**
         * Preserve the canonical target constraint without additional row filtering.
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
    $projections = app(CommentProjectionFactory::class);
    $identityKeys = [
        'actorType',
        'actorId',
        'moderatedByType',
        'moderatedBy',
        'deletedByType',
        'deletedBy',
        'restoredByType',
        'restoredBy',
        'anonymizedByType',
        'anonymizedBy',
    ];
    $withoutIdentity = $projections
        ->managementComment($comment, $target, $moderator)
        ->toArray();
    $withoutReportIdentity = CommentReportManagementData::fromModel(
        $report,
        $projections->canViewManagementIdentity($comment, $target, $moderator),
    )->toArray();

    $boundary->allowIdentity = true;
    $withIdentity = $projections
        ->managementComment($comment, $target, $moderator)
        ->toArray();
    $withReportIdentity = CommentReportManagementData::fromModel(
        $report,
        $projections->canViewManagementIdentity($comment, $target, $moderator),
    )->toArray();

    expect($boundary->allows(
        CommentAbility::Moderate,
        $moderator,
        $comment,
        $target,
        CommentAudience::Management,
    ))->toBeTrue()
        ->and(array_intersect($identityKeys, array_keys($withoutIdentity)))->toBe([])
        ->and($withoutReportIdentity)->not->toHaveKeys([
            'reporterType',
            'reporterId',
            'reviewedByType',
            'reviewedBy',
        ])
        ->and($withIdentity)->toHaveKeys($identityKeys)
        ->and($withIdentity['actorType'])->toBe('member')
        ->and($withIdentity['actorId'])->toBe('sensitive-author-id')
        ->and($withReportIdentity['reporterType'])->toBe('member')
        ->and($withReportIdentity['reporterId'])->toBe('sensitive-reporter-id');
});

it('returns every configured reaction in deterministic order with member viewer state only', function (): void {
    commentsV1RegisterRoutes(public: true, member: true);
    config()->set('comments.reactions.allowed', ['helpful', 'like', 'love']);

    $target = TestCommentTarget::query()->create(['name' => 'Reaction article']);
    $viewer = commentsV1Member('reaction-viewer');
    $viewerActor = CommentActorData::fromAuthenticatable($viewer);
    $otherActor = new CommentActorData('member', 'reaction-peer');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Reaction contract'),
        $viewerActor,
    );
    $reactions = app(SetCommentReactionAction::class);
    $reactions->execute($comment, 'helpful', true, $viewerActor);
    $reactions->execute($comment, 'helpful', true, $otherActor);
    $reactions->execute($comment, 'like', true, $otherActor);

    $publicResponse = $this->getJson(route(
        'nvl.comments.public.show',
        ['comment' => $comment->id],
    ))->assertOk()
        ->assertJsonPath('data.reactionCount', 3)
        ->assertJsonPath('data.reactions', [
            ['type' => 'helpful', 'count' => 2],
            ['type' => 'like', 'count' => 1],
            ['type' => 'love', 'count' => 0],
        ])
        ->assertJsonMissingPath('data.reactions.0.viewerActive');

    $memberResponse = $this->actingAs($viewer)->getJson(route(
        'nvl.comments.member.show',
        ['comment' => $comment->id],
    ))->assertOk()
        ->assertJsonPath('data.reactionCount', 3)
        ->assertJsonPath('data.reactions', [
            ['type' => 'helpful', 'count' => 2, 'viewerActive' => true],
            ['type' => 'like', 'count' => 1, 'viewerActive' => false],
            ['type' => 'love', 'count' => 0, 'viewerActive' => false],
        ]);

    expect($publicResponse->getContent())->not->toContain('reaction-viewer')
        ->and($publicResponse->getContent())->not->toContain('reaction-peer')
        ->and($memberResponse->getContent())->not->toContain('reaction-viewer')
        ->and($memberResponse->getContent())->not->toContain('reaction-peer');
});

it('returns minimal public and member tombstones without content actor reaction report or media facts', function (): void {
    commentsV1RegisterRoutes(public: true, member: true);

    $target = TestCommentTarget::query()->create(['name' => 'Tombstone article']);
    $author = commentsV1Member('tombstone-author-secret');
    $authorActor = CommentActorData::fromAuthenticatable($author);
    $reporter = new CommentActorData('member', 'tombstone-reporter-secret');
    $create = app(CreateCommentAction::class);
    $root = $create->execute(
        $target,
        new CreateCommentData(
            body: 'tombstone-body-secret',
            locale: 'en',
            tags: ['tombstone-tag-secret'],
            metadata: ['source' => 'tombstone-metadata-secret'],
        ),
        $authorActor,
    );
    $reply = $create->execute(
        $target,
        new CreateCommentData('Visible child', parentId: $root->id),
        $authorActor,
    );
    app(SetCommentReactionAction::class)->execute(
        $root,
        'helpful',
        true,
        $reporter,
    );
    app(ReportCommentAction::class)->execute(
        $root,
        new ReportCommentData('privacy', 'tombstone-report-details-secret'),
        $reporter,
    );
    $media = Media::factory()->create([
        'filename' => 'tombstone-safe-name.pdf',
        'hash' => 'tombstone-storage-hash-secret.pdf',
        'folder' => 'tombstone/storage/path/secret',
        'digest' => sha1('tombstone-digest-secret'),
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => $authorActor->type,
        'uploaded_by' => $authorActor->id,
    ]);
    app(AttachCommentMediaAction::class)->execute(
        $root,
        $media,
        $authorActor,
        CommentAudience::Member,
    );
    app(DeleteCommentAction::class)->execute(
        $root,
        new DeleteCommentData($root->revision),
        $authorActor,
        CommentAudience::Member,
    );

    $publicPayload = $this->getJson(route(
        'nvl.comments.public.show',
        ['comment' => $root->id],
    ))->assertOk()->json('data');
    $memberPayload = $this->actingAs($author)->getJson(route(
        'nvl.comments.member.show',
        ['comment' => $root->id],
    ))->assertOk()->json('data');
    $expectedKeys = [
        'createdAt',
        'depth',
        'id',
        'parentId',
        'replyCount',
        'revision',
        'rootId',
        'updatedAt',
    ];

    sort($expectedKeys);
    $publicKeys = array_keys($publicPayload);
    $memberKeys = array_keys($memberPayload);
    sort($publicKeys);
    sort($memberKeys);
    $encoded = json_encode(
        ['public' => $publicPayload, 'member' => $memberPayload],
        JSON_THROW_ON_ERROR,
    );

    expect($publicKeys)->toBe($expectedKeys)
        ->and($memberKeys)->toBe($expectedKeys)
        ->and($publicPayload['replyCount'] ?? null)->toBe(1)
        ->and($memberPayload['replyCount'] ?? null)->toBe(1)
        ->and($reply->parent_id)->toBe($root->id)
        ->and($encoded)->not->toContain('tombstone-body-secret')
        ->and($encoded)->not->toContain('tombstone-tag-secret')
        ->and($encoded)->not->toContain('tombstone-metadata-secret')
        ->and($encoded)->not->toContain('tombstone-author-secret')
        ->and($encoded)->not->toContain('tombstone-reporter-secret')
        ->and($encoded)->not->toContain('tombstone-report-details-secret')
        ->and($encoded)->not->toContain('tombstone-safe-name.pdf')
        ->and($encoded)->not->toContain('tombstone-storage-hash-secret.pdf')
        ->and($encoded)->not->toContain('tombstone/storage/path/secret');
});

it('counts audience-visible deleted replies as tombstones', function (): void {
    commentsV1RegisterRoutes(public: true);

    $target = TestCommentTarget::query()->create(['name' => 'Reply tombstone count']);
    $actor = new CommentActorData('member', 'reply-tombstone-author');
    $create = app(CreateCommentAction::class);
    $root = $create->execute(
        $target,
        new CreateCommentData('Active root'),
        $actor,
    );
    $reply = $create->execute(
        $target,
        new CreateCommentData('Deleted reply', parentId: $root->id),
        $actor,
    );
    app(DeleteCommentAction::class)->execute(
        $reply,
        new DeleteCommentData($reply->revision),
        $actor,
    );

    $this->getJson(route(
        'nvl.comments.public.show',
        ['comment' => $root->id],
    ))->assertOk()
        ->assertJsonPath('data.replyCount', 1);
    $this->getJson(route(
        'nvl.comments.public.show',
        ['comment' => $reply->id],
    ))->assertOk()
        ->assertJsonMissingPath('data.body');

    expect($root->refresh()->reply_count)->toBe(0);
});

it('conceals inaccessible comment and nested attachment identifiers from another target', function (): void {
    commentsV1RegisterRoutes(member: true);

    $viewerTarget = TestCommentTarget::query()->create(['name' => 'Viewer article']);
    $foreignTarget = TestCommentTarget::query()->create(['name' => 'Foreign article']);
    $viewer = commentsV1Member('target-viewer');
    $viewerActor = CommentActorData::fromAuthenticatable($viewer);
    $foreignComment = app(CreateCommentAction::class)->execute(
        $foreignTarget,
        new CreateCommentData(
            body: 'Cross-target private body',
            visibility: CommentVisibility::Private,
        ),
        new CommentActorData('member', 'foreign-target-author'),
        CommentAudience::Member,
    );
    app(CreateCommentAction::class)->execute(
        $viewerTarget,
        new CreateCommentData('Viewer target comment'),
        $viewerActor,
    );

    $this->actingAs($viewer)->getJson(route(
        'nvl.comments.member.show',
        ['comment' => $foreignComment->id],
    ))->assertNotFound();

    $this->getJson(route(
        'nvl.comments.member.attachments.index',
        ['comment' => $foreignComment->id],
    ))->assertNotFound()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
});

it('conceals orphaned canonical target facts from public and member identifiers', function (): void {
    commentsV1RegisterRoutes(public: true, member: true);

    $target = TestCommentTarget::query()->create(['name' => 'Removed target']);
    $targetId = $target->id;
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Orphaned comment body'),
        new CommentActorData('member', 'orphan-author'),
    );
    $targetType = $comment->commentable_type;
    $target->delete();

    $publicResponse = $this->getJson(route(
        'nvl.comments.public.show',
        ['comment' => $comment->id],
    ))->assertNotFound()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    $memberResponse = $this->actingAs(commentsV1Member('orphan-viewer'))
        ->getJson(route(
            'nvl.comments.member.show',
            ['comment' => $comment->id],
        ))->assertNotFound()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    $encoded = $publicResponse->getContent().$memberResponse->getContent();

    expect($encoded)->not->toContain($targetId)
        ->not->toContain($targetType)
        ->not->toContain('comment_target_not_found');
});

it('enforces the trusted query scope before every identifier mutation and history operation', function (): void {
    commentsV1RegisterRoutes(public: true, member: true, management: true);

    $allowedTarget = TestCommentTarget::query()->create(['name' => 'Allowed scope']);
    $blockedTarget = TestCommentTarget::query()->create(['name' => 'Blocked scope']);
    $viewer = commentsV1Member('scope-owner');
    $actor = CommentActorData::fromAuthenticatable($viewer);
    $create = app(CreateCommentAction::class);
    $allowedComment = $create->execute(
        $allowedTarget,
        new CreateCommentData('Allowed comment'),
        $actor,
    );
    $blockedComment = $create->execute(
        $blockedTarget,
        new CreateCommentData('Blocked comment'),
        $actor,
    );
    $blockedComment = app(UpdateCommentAction::class)->execute(
        $blockedComment,
        new UpdateCommentData('Blocked revision two', $blockedComment->revision),
        $actor,
    );
    $revision = $blockedComment->revisions()->firstOrFail();
    $report = app(ReportCommentAction::class)->execute(
        $blockedComment,
        new ReportCommentData('scope-test', 'Must remain open'),
        new CommentActorData('member', 'scope-reporter'),
    );
    $media = Media::factory()->create([
        'filename' => 'scope-attachment.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'type' => MediaType::DOCUMENT,
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => $actor->type,
        'uploaded_by' => $actor->id,
    ]);
    $association = app(AttachCommentMediaAction::class)->execute(
        $blockedComment,
        $media,
        $actor,
        CommentAudience::Member,
    );
    $deletedComment = $create->execute(
        $blockedTarget,
        new CreateCommentData('Blocked deleted comment'),
        $actor,
    );
    app(DeleteCommentAction::class)->execute(
        $deletedComment,
        new DeleteCommentData($deletedComment->revision),
        $actor,
        CommentAudience::Member,
    );
    $deletedComment = Comment::query()
        ->withTrashed()
        ->findOrFail($deletedComment->id);
    $boundary = new class($allowedTarget->id) implements CommentAuthorization, CommentQueryScope
    {
        public function __construct(private readonly string $allowedTargetId) {}

        /**
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
         * @param  Builder<Comment>  $query
         */
        public function scopeComments(
            Builder $query,
            CommentActorData $actor,
            Model $target,
            CommentAudience $audience,
            CommentAbility $ability,
        ): void {
            $query->where('commentable_id', $this->allowedTargetId);
        }
    };
    app()->instance(CommentAuthorization::class, $boundary);
    app()->instance(CommentQueryScope::class, $boundary);

    $assertNotFound = static function (callable $operation): void {
        expect($operation)->toThrow(ModelNotFoundException::class);
    };
    $assertNotFound(fn () => app(UpdateCommentAction::class)->execute(
        $blockedComment,
        new UpdateCommentData('Scope bypass', $blockedComment->revision),
        $actor,
        CommentAudience::Member,
    ));
    $assertNotFound(fn () => app(DeleteCommentAction::class)->execute(
        $blockedComment,
        new DeleteCommentData($blockedComment->revision),
        $actor,
        CommentAudience::Member,
    ));
    $assertNotFound(fn () => app(RestoreCommentAction::class)->execute(
        $deletedComment,
        new RestoreCommentData($deletedComment->revision),
        $actor,
        CommentAudience::Member,
    ));
    $assertNotFound(fn () => app(SetCommentReactionAction::class)->execute(
        $blockedComment,
        'like',
        true,
        $actor,
        CommentAudience::Member,
    ));
    $assertNotFound(fn () => app(ReportCommentAction::class)->execute(
        $blockedComment,
        new ReportCommentData('scope-bypass'),
        $actor,
        CommentAudience::Member,
    ));
    $assertNotFound(fn () => app(AttachCommentMediaAction::class)->execute(
        $blockedComment,
        $media,
        $actor,
        CommentAudience::Member,
    ));
    $assertNotFound(fn () => app(DetachCommentMediaAction::class)->execute(
        $blockedComment,
        $association->id,
        $actor,
        CommentAudience::Member,
    ));
    $assertNotFound(fn () => app(ListCommentAttachmentsAction::class)->execute(
        $blockedComment,
        $actor,
        CommentAudience::Member,
    ));
    $assertNotFound(fn () => app(ListCommentRevisionsAction::class)->execute(
        $blockedComment,
        $actor,
        audience: CommentAudience::Member,
    ));
    $assertNotFound(fn () => app(RestoreCommentRevisionAction::class)->execute(
        $blockedComment,
        $revision,
        new RestoreCommentRevisionData($blockedComment->revision),
        $actor,
        CommentAudience::Member,
    ));
    $assertNotFound(fn () => app(ModerateCommentAction::class)->execute(
        $blockedComment,
        new ModerateCommentData(
            CommentStatus::Hidden,
            $blockedComment->revision,
        ),
        CommentActorData::system(),
    ));
    $assertNotFound(fn () => app(ResolveCommentReportAction::class)->execute(
        $report,
        new ResolveCommentReportData(
            CommentReportStatus::Resolved,
            $blockedComment->revision,
            'Scope must win',
        ),
        CommentActorData::system(),
    ));
    $assertNotFound(fn () => app(AnonymizeCommentAction::class)->execute(
        $blockedComment,
        new AnonymizeCommentData(
            $blockedComment->revision,
            'Scope must win',
        ),
        CommentActorData::system(),
        CommentAudience::Management,
    ));
    $assertNotFound(fn () => $create->execute(
        $allowedTarget,
        new CreateCommentData(
            'Cross-target reply',
            parentId: $blockedComment->id,
        ),
        $actor,
        CommentAudience::Member,
    ));

    expect($blockedComment->refresh()->body)->toBe('Blocked revision two')
        ->and($blockedComment->revision)->toBe(2)
        ->and($blockedComment->reaction_count)->toBe(0)
        ->and($blockedComment->report_count)->toBe(1)
        ->and($blockedComment->open_report_count)->toBe(1)
        ->and($report->refresh()->status)->toBe(CommentReportStatus::Open)
        ->and(MediaAssociation::query()->whereKey($association->id)->exists())
        ->toBeTrue()
        ->and($deletedComment->refresh()->trashed())->toBeTrue()
        ->and($allowedComment->refresh()->reply_count)->toBe(0);
});

it('conceals a visible parent when the actor may not reply', function (): void {
    commentsV1RegisterRoutes(member: true);

    $target = TestCommentTarget::query()->create(['name' => 'Reply denial']);
    $viewer = commentsV1Member('non-replying-viewer');
    $parent = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Visible parent'),
        new CommentActorData('member', 'reply-parent-author'),
    );
    $boundary = new class implements CommentAuthorization, CommentQueryScope
    {
        /**
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
            return $ability === CommentAbility::View;
        }

        /**
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

    $this->actingAs($viewer)->postJson(route(
        'nvl.comments.member.store',
        ['target' => 'article', 'targetId' => $target->id],
    ), [
        'body' => 'Denied reply',
        'format' => 'plain',
        'parentId' => $parent->id,
        'visibility' => 'public',
    ])->assertNotFound();

    expect(Comment::query()->count())->toBe(1)
        ->and($parent->refresh()->reply_count)->toBe(0);
});

it('does not reopen an unrelated authorization gate while projecting mutations', function (): void {
    commentsV1RegisterRoutes(member: true, management: true);

    $target = TestCommentTarget::query()->create(['name' => 'Granular projection policy']);
    $viewer = commentsV1Member('granular-operation-actor');
    $actor = CommentActorData::fromAuthenticatable($viewer);
    $create = app(CreateCommentAction::class);
    $updatable = $create->execute(
        $target,
        new CreateCommentData('Before granular update'),
        $actor,
    );
    $anonymizable = $create->execute(
        $target,
        new CreateCommentData('Before granular anonymization'),
        new CommentActorData('member', 'granular-other-author'),
    );
    $boundary = new class implements CommentAuthorization, CommentQueryScope
    {
        /**
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
            return in_array($ability, [
                CommentAbility::Update,
                CommentAbility::Anonymize,
            ], true);
        }

        /**
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

    $this->actingAs($viewer)->patchJson(route(
        'nvl.comments.member.update',
        ['comment' => $updatable->id],
    ), [
        'body' => 'After granular update',
        'expectedRevision' => $updatable->revision,
    ])->assertOk()
        ->assertJsonPath('data.body', 'After granular update');

    $this->actingAs($viewer)->postJson(route(
        'nvl.comments.management.anonymize',
        ['comment' => $anonymizable->id],
    ), [
        'expectedRevision' => $anonymizable->revision,
        'reason' => 'Granular terminal operation',
    ])->assertOk()
        ->assertJsonPath('data.anonymizedAt', fn (mixed $value): bool => is_string($value));

    expect($updatable->refresh()->body)->toBe('After granular update')
        ->and(Comment::query()->withTrashed()->findOrFail($anonymizable->id)->anonymized_at)
        ->not->toBeNull();
});

it('requires current authorization and reply scope for exact idempotency replays', function (): void {
    commentsV1RegisterRoutes(member: true);

    $target = TestCommentTarget::query()->create(['name' => 'Replay access policy']);
    $viewer = commentsV1Member('replay-access-actor');
    $actor = CommentActorData::fromAuthenticatable($viewer);
    $create = app(CreateCommentAction::class);
    $rootKey = (string) Str::uuid();
    $rootData = new CreateCommentData(
        body: 'Private replay secret',
        visibility: CommentVisibility::Private,
        idempotencyKey: $rootKey,
    );
    $createdRoot = $create->execute(
        $target,
        $rootData,
        $actor,
        CommentAudience::Member,
    );
    $parent = $create->execute(
        $target,
        new CreateCommentData('Replay parent'),
        $actor,
        CommentAudience::Member,
    );
    $replyKey = (string) Str::uuid();
    $replyData = new CreateCommentData(
        body: 'Scoped replay secret',
        parentId: $parent->id,
        idempotencyKey: $replyKey,
    );
    $createdReply = $create->execute(
        $target,
        $replyData,
        $actor,
        CommentAudience::Member,
    );
    Event::fake([CommentChanged::class]);
    $deniedPolicy = new class implements CommentAuthorization, CommentQueryScope
    {
        /**
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
    app()->instance(CommentAuthorization::class, $deniedPolicy);
    app()->instance(CommentQueryScope::class, $deniedPolicy);

    $rootReplay = $this->actingAs($viewer)->postJson(route(
        'nvl.comments.member.store',
        ['target' => 'article', 'targetId' => $target->id],
    ), [
        'body' => $rootData->body,
        'format' => $rootData->format->value,
        'visibility' => $rootData->visibility->value,
        'idempotencyKey' => $rootKey,
    ])->assertNotFound();

    $deniedScope = new class implements CommentAuthorization, CommentQueryScope
    {
        /**
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
         * @param  Builder<Comment>  $query
         */
        public function scopeComments(
            Builder $query,
            CommentActorData $actor,
            Model $target,
            CommentAudience $audience,
            CommentAbility $ability,
        ): void {
            $query->whereRaw('1 = 0');
        }
    };
    app()->instance(CommentAuthorization::class, $deniedScope);
    app()->instance(CommentQueryScope::class, $deniedScope);

    $replyReplay = $this->actingAs($viewer)->postJson(route(
        'nvl.comments.member.store',
        ['target' => 'article', 'targetId' => $target->id],
    ), [
        'body' => $replyData->body,
        'format' => $replyData->format->value,
        'parentId' => $parent->id,
        'visibility' => $replyData->visibility->value,
        'idempotencyKey' => $replyKey,
    ])->assertNotFound();

    expect($rootReplay->getContent())->not->toContain($rootData->body)
        ->and($replyReplay->getContent())->not->toContain($replyData->body)
        ->and(Comment::query()->count())->toBe(3)
        ->and($createdRoot->refresh()->body)->toBe($rootData->body)
        ->and($createdReply->refresh()->body)->toBe($replyData->body);
    Event::assertNotDispatched(CommentChanged::class);
});

it('exposes only safe attachment fields and detaches an association idempotently without deleting media', function (): void {
    commentsV1RegisterRoutes(public: true, member: true);

    $target = TestCommentTarget::query()->create(['name' => 'Attachment article']);
    $author = commentsV1Member('attachment-owner');
    $authorActor = CommentActorData::fromAuthenticatable($author);
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Attachment contract'),
        $authorActor,
    );
    $media = Media::factory()->create([
        'filename' => 'safe-handbook.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'type' => MediaType::DOCUMENT,
        'hash' => 'storage-hash-must-not-leak.pdf',
        'folder' => 'private/storage/path/must-not-leak',
        'digest' => sha1('digest-must-not-leak'),
        'metadata' => ['internal' => 'media-metadata-must-not-leak'],
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => $authorActor->type,
        'uploaded_by' => $authorActor->id,
    ]);
    $association = app(AttachCommentMediaAction::class)->execute(
        $comment,
        $media,
        $authorActor,
        CommentAudience::Member,
    );
    Storage::disk($media->disk)->put(
        $media->buildPath(),
        'opaque attachment delivery',
    );

    $response = $this->actingAs($author)->getJson(route(
        'nvl.comments.member.attachments.index',
        ['comment' => $comment->id],
    ))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.associationId', $association->id)
        ->assertJsonPath('data.0.name', 'safe-handbook.pdf')
        ->assertJsonPath('data.0.mimeType', 'application/pdf')
        ->assertJsonPath('data.0.canRemove', true)
        ->assertJsonMissingPath('data.0.mediaId')
        ->assertJsonMissingPath('data.0.disk')
        ->assertJsonMissingPath('data.0.path')
        ->assertJsonMissingPath('data.0.hash')
        ->assertJsonMissingPath('data.0.digest')
        ->assertJsonMissingPath('data.0.metadata')
        ->assertJsonMissingPath('data.0.uploadedBy')
        ->assertJsonMissingPath('data.0.uploadedByType');
    $attachment = $response->json('data.0');
    config()->set('comments.attachments.allow_public_media', true);
    $publicMedia = Media::factory()->create([
        'filename' => 'public-handbook.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'type' => MediaType::DOCUMENT,
        'is_public' => true,
        'visibility' => MediaVisibility::Public,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => $authorActor->type,
        'uploaded_by' => $authorActor->id,
    ]);
    MediaAssociation::query()->create([
        'media_id' => $publicMedia->id,
        'associable_type' => $comment->getMorphClass(),
        'associable_id' => $comment->id,
        'collection' => 'attachments',
        'order' => 1,
        'is_active' => true,
    ]);
    $publicResponse = $this->actingAs($author)->getJson(route(
        'nvl.comments.public.attachments.index',
        ['comment' => $comment->id],
    ))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'public-handbook.pdf')
        ->assertJsonPath('data.0.canRemove', false);
    $opaqueUrls = json_encode([
        $attachment['assetUrl'],
        $attachment['thumbnailUrl'],
    ], JSON_THROW_ON_ERROR);

    expect(array_keys($attachment))->toBe([
        'associationId',
        'kind',
        'name',
        'mimeType',
        'size',
        'title',
        'alt',
        'assetUrl',
        'thumbnailUrl',
        'canRemove',
        'createdAt',
    ])->and($response->getContent())
        ->not->toContain('storage-hash-must-not-leak.pdf')
        ->not->toContain('private/storage/path/must-not-leak')
        ->not->toContain('media-metadata-must-not-leak')
        ->and($opaqueUrls)->toContain($association->id)
        ->not->toContain($media->id)
        ->not->toContain((string) $authorActor->id)
        ->not->toContain('storage-hash-must-not-leak.pdf')
        ->not->toContain('private/storage/path/must-not-leak')
        ->and($publicResponse->headers->get('Cache-Control'))
        ->toContain('public', 'max-age=60', 's-maxage=60');

    $assetResponse = $this->actingAs($author)->get($attachment['assetUrl'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    $thumbnailResponse = $this->actingAs($author)->get(
        app(CommentAttachmentUrlFactory::class)->thumbnail($association),
    )->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    $invalidSignature = $this->get($attachment['assetUrl'].'&tampered=1')
        ->assertForbidden();

    expect($assetResponse->headers->get('Cache-Control'))
        ->toBe('max-age=0, no-store, private')
        ->and($thumbnailResponse->headers->get('Cache-Control'))
        ->toBe('max-age=0, no-store, private')
        ->and($invalidSignature->headers->get('Cache-Control'))
        ->toBe('max-age=0, no-store, private');

    $wrongCollection = MediaAssociation::query()->create([
        'media_id' => $media->id,
        'associable_type' => $comment->getMorphClass(),
        'associable_id' => $comment->id,
        'collection' => 'not-comment-attachments',
        'order' => 0,
        'is_active' => true,
    ]);
    $otherComment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Other attachment owner'),
        $authorActor,
    );
    $otherMedia = Media::factory()->create([
        'filename' => 'other-owner.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'type' => MediaType::DOCUMENT,
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => $authorActor->type,
        'uploaded_by' => $authorActor->id,
    ]);
    $wrongOwner = MediaAssociation::query()->create([
        'media_id' => $otherMedia->id,
        'associable_type' => $otherComment->getMorphClass(),
        'associable_id' => $otherComment->id,
        'collection' => 'attachments',
        'order' => 0,
        'is_active' => true,
    ]);
    $this->deleteJson(route('nvl.comments.member.attachments.destroy', [
        'comment' => $comment->id,
        'association' => $wrongCollection->id,
    ]))->assertNoContent();
    $this->deleteJson(route('nvl.comments.member.attachments.destroy', [
        'comment' => $comment->id,
        'association' => $wrongOwner->id,
    ]))->assertNoContent();

    expect(MediaAssociation::query()->whereKey($wrongCollection->id)->exists())->toBeTrue()
        ->and(MediaAssociation::query()->whereKey($wrongOwner->id)->exists())->toBeTrue();

    $detachUrl = route('nvl.comments.member.attachments.destroy', [
        'comment' => $comment->id,
        'association' => $association->id,
    ]);

    $this->deleteJson($detachUrl)->assertNoContent();
    $this->deleteJson($detachUrl)->assertNoContent();

    expect(Media::query()->whereKey($media->id)->exists())->toBeTrue()
        ->and(MediaAssociation::query()->whereKey($association->id)->exists())->toBeFalse();
});

it('does not commit an attachment when media delivery is not authorized', function (): void {
    commentsV1RegisterRoutes(member: true);

    $target = TestCommentTarget::query()->create(['name' => 'Delivery boundary']);
    $author = commentsV1Member('delivery-owner');
    $actor = CommentActorData::fromAuthenticatable($author);
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Delivery authorization'),
        $actor,
    );
    $media = Media::factory()->create([
        'filename' => 'undeliverable.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'type' => MediaType::DOCUMENT,
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => $actor->type,
        'uploaded_by' => $actor->id,
    ]);
    app()->instance(MediaAuthorization::class, new class implements MediaAuthorization
    {
        public function allows(
            MediaActorData $actor,
            MediaAbility $ability,
            ?Media $media = null,
            ?Model $owner = null,
        ): bool {
            return $ability === MediaAbility::Associate;
        }
    });

    $this->actingAs($author)->postJson(route(
        'nvl.comments.member.attachments.store',
        ['comment' => $comment->id],
    ), [
        'mediaId' => $media->id,
    ])->assertForbidden();

    expect(MediaAssociation::query()
        ->where('media_id', $media->id)
        ->where('associable_id', $comment->id)
        ->exists())->toBeFalse();
});

it('does not commit an attachment when signed delivery routes are unavailable', function (): void {
    config()->set('comments.routes.attachments.enabled', false);
    commentsV1RegisterRoutes(member: true);

    $target = TestCommentTarget::query()->create(['name' => 'Missing delivery route']);
    $author = commentsV1Member('missing-delivery-owner');
    $actor = CommentActorData::fromAuthenticatable($author);
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Delivery route preflight'),
        $actor,
    );
    $media = Media::factory()->create([
        'filename' => 'route-unavailable.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'type' => MediaType::DOCUMENT,
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => $actor->type,
        'uploaded_by' => $actor->id,
    ]);

    $this->actingAs($author)->postJson(route(
        'nvl.comments.member.attachments.store',
        ['comment' => $comment->id],
    ), [
        'mediaId' => $media->id,
    ])->assertStatus(503)
        ->assertJsonPath('code', 'comment_attachment_delivery_unavailable');

    expect(MediaAssociation::query()
        ->where('media_id', $media->id)
        ->where('associable_id', $comment->id)
        ->exists())->toBeFalse();
});

it('keeps public attachment metadata deterministic across request locales', function (): void {
    commentsV1RegisterRoutes(public: true, member: true);
    config()->set([
        'comments.attachments.allow_public_media' => true,
        'comments.attachments.signed_url_lifetime' => 1,
        'comments.cache.public_max_age' => 600,
    ]);

    $target = TestCommentTarget::query()->create(['name' => 'Localized attachment']);
    $author = commentsV1Member('localized-attachment-author');
    $actor = CommentActorData::fromAuthenticatable($author);
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Localized attachment metadata'),
        $actor,
    );
    $media = Media::factory()->create([
        'filename' => 'localized-public.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'type' => MediaType::DOCUMENT,
        'is_public' => true,
        'visibility' => MediaVisibility::Public,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => $actor->type,
        'uploaded_by' => $actor->id,
    ]);
    $media->translations()->create([
        'locale' => 'en',
        'title' => 'English public title',
        'alt' => 'English public alternative',
    ]);
    $media->translations()->create([
        'locale' => 'bg',
        'title' => 'Bulgarian member title',
        'alt' => 'Bulgarian member alternative',
    ]);
    app(AttachCommentMediaAction::class)->execute(
        $comment,
        $media,
        $actor,
        CommentAudience::Member,
    );
    app(ContentLocale::class)->set('bg');

    $publicBulgarianContext = $this->getJson(route(
        'nvl.comments.public.attachments.index',
        ['comment' => $comment->id],
    ))->assertOk()
        ->assertJsonPath('data.0.title', 'English public title');
    $memberBulgarianContext = $this->actingAs($author)->getJson(route(
        'nvl.comments.member.attachments.index',
        ['comment' => $comment->id],
    ))->assertOk()
        ->assertJsonPath('data.0.title', 'Bulgarian member title');
    app(ContentLocale::class)->set('en');
    $publicEnglishContext = $this->getJson(route(
        'nvl.comments.public.attachments.index',
        ['comment' => $comment->id],
    ))->assertOk()
        ->assertJsonPath('data.0.title', 'English public title');

    expect($publicBulgarianContext->json('data.0.title'))
        ->toBe($publicEnglishContext->json('data.0.title'))
        ->and($publicBulgarianContext->headers->get('Cache-Control'))
        ->toContain('public', 'max-age=30', 's-maxage=30')
        ->and($memberBulgarianContext->headers->get('Cache-Control'))
        ->toBe('max-age=0, no-store, private');
});

it('delivers a signed attachment authorized in the owning comment context', function (): void {
    commentsV1RegisterRoutes(member: true);

    $target = TestCommentTarget::query()->create(['name' => 'Context delivery']);
    $author = commentsV1Member('context-delivery-owner');
    $actor = CommentActorData::fromAuthenticatable($author);
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Comment-context attachment'),
        $actor,
    );
    $media = Media::factory()->create([
        'filename' => 'context-only.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'type' => MediaType::DOCUMENT,
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => $actor->type,
        'uploaded_by' => $actor->id,
    ]);
    app()->instance(MediaAuthorization::class, new class implements MediaAuthorization
    {
        public function allows(
            MediaActorData $actor,
            MediaAbility $ability,
            ?Media $media = null,
            ?Model $owner = null,
        ): bool {
            return $owner instanceof Comment
                && in_array($ability, [
                    MediaAbility::Associate,
                    MediaAbility::View,
                    MediaAbility::Download,
                    MediaAbility::Mutate,
                ], true);
        }
    });
    $association = app(AttachCommentMediaAction::class)->execute(
        $comment,
        $media,
        $actor,
        CommentAudience::Member,
    );
    Storage::disk($media->disk)->put(
        $media->buildPath(),
        'comment-context delivery',
    );
    $attachment = $this->actingAs($author)->getJson(route(
        'nvl.comments.member.attachments.index',
        ['comment' => $comment->id],
    ))->assertOk()
        ->assertJsonPath('data.0.associationId', $association->id)
        ->json('data.0');

    $this->actingAs($author)->get($attachment['assetUrl'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
});

it('keeps populated public member and management projection queries constant from one to twenty five comments', function (): void {
    config()->set('comments.attachments.allow_public_media', true);

    $singleTarget = TestCommentTarget::query()->create(['name' => 'Single projection']);
    $batchTarget = TestCommentTarget::query()->create(['name' => 'Batch projection']);
    $viewer = commentsV1Member('projection-viewer');
    $viewerActor = CommentActorData::fromAuthenticatable($viewer);
    $reporter = new CommentActorData('member', 'projection-reporter');
    $create = app(CreateCommentAction::class);
    $singleComment = $create->execute(
        $singleTarget,
        new CreateCommentData('Single comment'),
        $viewerActor,
    );
    $batchComments = [];

    foreach (range(1, 25) as $number) {
        $batchComments[] = $create->execute(
            $batchTarget,
            new CreateCommentData("Batch comment {$number}"),
            $viewerActor,
        );
    }

    /**
     * Populate every batched projection relation and aggregate.
     *
     * @param  list<Comment>  $comments
     */
    $populate = function (
        TestCommentTarget $target,
        array $comments,
        string $prefix,
    ) use ($create, $reporter, $viewerActor): void {
        foreach ($comments as $index => $comment) {
            $create->execute(
                $target,
                new CreateCommentData(
                    "{$prefix} reply {$index}",
                    parentId: $comment->id,
                ),
                $viewerActor,
            );
            app(SetCommentReactionAction::class)->execute(
                $comment,
                'like',
                true,
                $viewerActor,
            );
            app(ReportCommentAction::class)->execute(
                $comment,
                new ReportCommentData('projection-query-count'),
                $reporter,
            );
            $media = Media::factory()->create([
                'filename' => "{$prefix}-{$index}.pdf",
                'extension' => 'pdf',
                'mime_type' => 'application/pdf',
                'type' => MediaType::DOCUMENT,
                'is_public' => true,
                'visibility' => MediaVisibility::Public,
                'status' => MediaLifecycleStatus::Available,
                'uploaded_by_type' => $viewerActor->type,
                'uploaded_by' => $viewerActor->id,
            ]);
            $media->translations()->create([
                'locale' => 'en',
                'title' => "{$prefix} title {$index}",
                'alt' => "{$prefix} alternative {$index}",
            ]);
            app(AttachCommentMediaAction::class)->execute(
                $comment,
                $media,
                $viewerActor,
                CommentAudience::Member,
            );
        }
    };
    $populate($singleTarget, [$singleComment], 'single');
    $populate($batchTarget, $batchComments, 'batch');
    $boundary = new class implements CommentAuthorization, CommentQueryScope
    {
        /**
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
    app()->instance(
        CommentAuthorPresenter::class,
        new class implements CommentAuthorPresenter
        {
            /**
             * Resolve the whole batch in one query and return opaque presentation.
             *
             * @param  EloquentCollection<int, Comment>  $comments
             * @return array<string, CommentAuthorData|null>
             */
            public function present(
                EloquentCollection $comments,
                CommentAudience $audience,
            ): array {
                $ids = Comment::query()
                    ->whereIn('id', $comments->modelKeys())
                    ->pluck('id')
                    ->all();
                $presented = [];

                foreach ($ids as $id) {
                    if (is_string($id)) {
                        $presented[$id] = CommentAuthorData::opaque();
                    }
                }

                return $presented;
            }
        },
    );
    $projections = app(CommentProjectionFactory::class);
    $connection = DB::connection();
    $measure = static function (callable $callback) use ($connection): int {
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $callback();

            return count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
        }
    };
    $singleCollection = new EloquentCollection([$singleComment]);
    $batchCollection = new EloquentCollection($batchComments);
    $singlePublicQueries = $measure(
        static fn (): array => $projections->publicCollection(
            $singleCollection,
            $singleTarget,
        ),
    );
    $batchPublicQueries = $measure(
        static fn (): array => $projections->publicCollection(
            $batchCollection,
            $batchTarget,
        ),
    );
    $singleMemberQueries = $measure(
        static fn (): array => $projections->memberCollection(
            $singleCollection,
            $singleTarget,
            $viewerActor,
        ),
    );
    $batchMemberQueries = $measure(
        static fn (): array => $projections->memberCollection(
            $batchCollection,
            $batchTarget,
            $viewerActor,
        ),
    );
    $singleManagementQueries = $measure(
        static fn (): array => $projections->managementCollection(
            $singleCollection,
            $singleTarget,
            $viewerActor,
        ),
    );
    $batchManagementQueries = $measure(
        static fn (): array => $projections->managementCollection(
            $batchCollection,
            $batchTarget,
            $viewerActor,
        ),
    );

    expect($singlePublicQueries)->toBe($batchPublicQueries)
        ->and($singlePublicQueries)->toBeLessThanOrEqual(8)
        ->and($singleMemberQueries)->toBe($batchMemberQueries)
        ->and($singleMemberQueries)->toBeLessThanOrEqual(9)
        ->and($singleManagementQueries)->toBe($batchManagementQueries)
        ->and($singleManagementQueries)->toBeLessThanOrEqual(2);
});

it('derives management reply counts through the trusted operation scope', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Scoped moderation counts']);
    $actor = new CommentActorData('member', 'management-author');
    $create = app(CreateCommentAction::class);
    $root = $create->execute(
        $target,
        new CreateCommentData('Moderation root'),
        $actor,
    );
    $create->execute(
        $target,
        new CreateCommentData('Visible reply', parentId: $root->id),
        $actor,
    );
    $hiddenReply = $create->execute(
        $target,
        new CreateCommentData('Scope-hidden reply', parentId: $root->id),
        $actor,
    );
    $boundary = new class($hiddenReply->id) implements CommentAuthorization, CommentQueryScope
    {
        public function __construct(private readonly string $hiddenCommentId) {}

        /**
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
         * @param  Builder<Comment>  $query
         */
        public function scopeComments(
            Builder $query,
            CommentActorData $actor,
            Model $target,
            CommentAudience $audience,
            CommentAbility $ability,
        ): void {
            $query->whereKeyNot($this->hiddenCommentId);
        }
    };
    app()->instance(CommentAuthorization::class, $boundary);
    app()->instance(CommentQueryScope::class, $boundary);

    $projection = app(CommentProjectionFactory::class)->managementComment(
        $root->refresh(),
        $target,
        CommentActorData::system(),
    );

    expect($root->refresh()->reply_count)->toBe(2)
        ->and($projection->replyCount)->toBe(1);
});
