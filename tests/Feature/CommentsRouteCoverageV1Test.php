<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Nvl\Comments\Actions\AttachCommentMediaAction;
use Nvl\Comments\Actions\CreateCommentAction;
use Nvl\Comments\Actions\DeleteCommentAction;
use Nvl\Comments\Actions\ReportCommentAction;
use Nvl\Comments\Actions\UpdateCommentAction;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Data\Mutations\DeleteCommentData;
use Nvl\Comments\Data\Mutations\ReportCommentData;
use Nvl\Comments\Data\Mutations\UpdateCommentData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media;

beforeEach(function (): void {
    config()->set([
        'comments.routes.public.enabled' => true,
        'comments.routes.member.enabled' => true,
        'comments.routes.management.enabled' => true,
    ]);

    require dirname(__DIR__, 2).'/routes/api.php';
    require dirname(__DIR__, 3).'/media/routes/assets.php';

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();

    $commentsBoundary = new class implements CommentAuthorization, CommentQueryScope
    {
        /**
         * Permit each route while the test asserts its domain result.
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
         * Preserve the target constraint already applied by the package.
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
    $mediaBoundary = new class implements MediaAuthorization
    {
        public function allows(
            MediaActorData $actor,
            MediaAbility $ability,
            ?Media $media = null,
            ?Model $owner = null,
        ): bool {
            return true;
        }
    };

    app()->instance(CommentAuthorization::class, $commentsBoundary);
    app()->instance(CommentQueryScope::class, $commentsBoundary);
    app()->instance(MediaAuthorization::class, $mediaBoundary);
});

/**
 * Create a request actor for complete route traversal.
 */
function commentsRouteCoverageUser(string $id): GenericUser
{
    return new GenericUser(['id' => $id]);
}

/**
 * Create one deliverable private document for attachment route coverage.
 */
function commentsRouteCoverageMedia(CommentActorData $actor): Media
{
    return Media::factory()->create([
        'filename' => 'comments-route-file.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'type' => MediaType::DOCUMENT,
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => $actor->type,
        'uploaded_by' => $actor->id,
    ]);
}

it('exercises every public comments route', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Public route target']);
    $user = commentsRouteCoverageUser('public-route-author');
    $actor = CommentActorData::fromAuthenticatable($user);

    $created = $this->actingAs($user)->postJson(route(
        'nvl.comments.public.store',
        ['target' => 'article', 'targetId' => $target->id],
    ), [
        'body' => 'Public route body',
        'format' => 'plain',
        'visibility' => 'public',
    ], [
        'Idempotency-Key' => '144d40ca-2469-42c7-a793-12bbd41ecb9c',
    ])->assertCreated();
    $commentId = $created->json('data.id');

    expect($commentId)->toBeString();

    $this->getJson(route(
        'nvl.comments.public.index',
        ['target' => 'article', 'targetId' => $target->id],
    ))->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson(route(
        'nvl.comments.public.show',
        ['comment' => $commentId],
    ))->assertOk()->assertJsonPath('data.id', $commentId);
    $updated = $this->actingAs($user)->patchJson(route(
        'nvl.comments.public.update',
        ['comment' => $commentId],
    ), [
        'body' => 'Updated public route body',
        'expectedRevision' => 1,
    ])->assertOk()->assertJsonPath('data.revision', 2);

    $this->actingAs($user)->putJson(route(
        'nvl.comments.public.reaction',
        ['comment' => $commentId],
    ), [
        'type' => 'like',
        'active' => true,
    ])->assertOk()->assertJsonPath('data.reactionCount', 1);
    $this->actingAs($user)->postJson(route(
        'nvl.comments.public.reports.store',
        ['comment' => $commentId],
    ), [
        'reason' => 'route-coverage',
        'details' => 'Public report route',
    ])->assertAccepted()->assertJsonPath('data.reported', true);

    $media = commentsRouteCoverageMedia($actor);
    $attached = $this->actingAs($user)->postJson(route(
        'nvl.comments.public.attachments.store',
        ['comment' => $commentId],
    ), [
        'mediaId' => $media->id,
    ])->assertCreated()
        ->assertJsonStructure(['data' => [
            'associationId',
            'assetUrl',
            'thumbnailUrl',
        ]]);
    $associationId = $attached->json('data.associationId');

    expect($associationId)->toBeString();

    $this->actingAs($user)->getJson(route(
        'nvl.comments.public.attachments.index',
        ['comment' => $commentId],
    ))->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($user)->deleteJson(route(
        'nvl.comments.public.attachments.destroy',
        ['comment' => $commentId, 'association' => $associationId],
    ))->assertNoContent();
    $this->actingAs($user)->deleteJson(route(
        'nvl.comments.public.destroy',
        ['comment' => $commentId],
    ), [
        'expectedRevision' => $updated->json('data.revision'),
    ])->assertOk()->assertJsonMissingPath('data.body');
});

it('exercises every member comments route independently', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Member route target']);
    $user = commentsRouteCoverageUser('member-route-author');
    $actor = CommentActorData::fromAuthenticatable($user);
    $created = $this->actingAs($user)->postJson(route(
        'nvl.comments.member.store',
        ['target' => 'article', 'targetId' => $target->id],
    ), [
        'body' => 'Member route body',
        'format' => 'plain',
        'visibility' => 'private',
    ])->assertCreated();
    $commentId = $created->json('data.id');

    expect($commentId)->toBeString();

    $this->actingAs($user)->getJson(route(
        'nvl.comments.member.index',
        ['target' => 'article', 'targetId' => $target->id],
    ))->assertOk()->assertJsonPath('meta.total', 1);
    $this->actingAs($user)->getJson(route(
        'nvl.comments.member.show',
        ['comment' => $commentId],
    ))->assertOk()->assertJsonPath('data.isAuthor', true);
    $updated = $this->actingAs($user)->patchJson(route(
        'nvl.comments.member.update',
        ['comment' => $commentId],
    ), [
        'body' => 'Updated member route body',
        'expectedRevision' => 1,
    ])->assertOk()->assertJsonPath('data.revision', 2);

    $this->actingAs($user)->putJson(route(
        'nvl.comments.member.reaction',
        ['comment' => $commentId],
    ), [
        'type' => 'helpful',
        'active' => true,
    ])->assertOk()->assertJsonPath('data.reactionCount', 1);
    $this->actingAs($user)->postJson(route(
        'nvl.comments.member.reports.store',
        ['comment' => $commentId],
    ), [
        'reason' => 'route-coverage',
    ])->assertAccepted()->assertJsonPath('data.reported', true);

    $media = commentsRouteCoverageMedia($actor);
    $attached = $this->actingAs($user)->postJson(route(
        'nvl.comments.member.attachments.store',
        ['comment' => $commentId],
    ), [
        'mediaId' => $media->id,
    ])->assertCreated();
    $associationId = $attached->json('data.associationId');

    expect($associationId)->toBeString();

    $this->actingAs($user)->getJson(route(
        'nvl.comments.member.attachments.index',
        ['comment' => $commentId],
    ))->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($user)->deleteJson(route(
        'nvl.comments.member.attachments.destroy',
        ['comment' => $commentId, 'association' => $associationId],
    ))->assertNoContent();

    $revisions = $this->actingAs($user)->getJson(route(
        'nvl.comments.member.revisions.index',
        ['comment' => $commentId],
    ))->assertOk()->assertJsonCount(1, 'data');
    $revisionId = $revisions->json('data.0.id');

    expect($revisionId)->toBeString();

    $restoredRevision = $this->actingAs($user)->postJson(route(
        'nvl.comments.member.revisions.restore',
        ['comment' => $commentId, 'revision' => $revisionId],
    ), [
        'expectedRevision' => $updated->json('data.revision'),
    ])->assertOk()->assertJsonPath('data.revision', 3);
    $deleted = $this->actingAs($user)->deleteJson(route(
        'nvl.comments.member.destroy',
        ['comment' => $commentId],
    ), [
        'expectedRevision' => $restoredRevision->json('data.revision'),
    ])->assertOk();

    $this->actingAs($user)->postJson(route(
        'nvl.comments.member.restore',
        ['comment' => $commentId],
    ), [
        'expectedRevision' => $deleted->json('data.revision'),
    ])->assertOk()->assertJsonPath('data.revision', 5);
});

it('exercises every management comments route', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Management route target']);
    $author = new CommentActorData('member', 'management-route-author');
    $moderator = commentsRouteCoverageUser('management-route-moderator');
    $create = app(CreateCommentAction::class);
    $comment = $create->execute(
        $target,
        new CreateCommentData('Management route body'),
        $author,
    );
    $updated = app(UpdateCommentAction::class)->execute(
        $comment,
        new UpdateCommentData('Management route update', $comment->revision),
        $author,
    );
    $report = app(ReportCommentAction::class)->execute(
        $updated,
        new ReportCommentData('route-coverage', 'Management report route'),
        new CommentActorData('member', 'management-route-reporter'),
    );
    $media = commentsRouteCoverageMedia($author);
    $association = app(AttachCommentMediaAction::class)->execute(
        $updated,
        $media,
        $author,
        CommentAudience::Member,
    );

    $this->actingAs($moderator)->getJson(route(
        'nvl.comments.management.index',
        ['target' => 'article', 'targetId' => $target->id],
    ))->assertOk()->assertJsonPath('meta.total', 1);
    $this->actingAs($moderator)->getJson(route(
        'nvl.comments.management.target_reports.index',
        ['target' => 'article', 'targetId' => $target->id],
    ))->assertOk()->assertJsonCount(1, 'data');
    $moderated = $this->actingAs($moderator)->putJson(route(
        'nvl.comments.management.moderate',
        ['comment' => $comment->id],
    ), [
        'status' => CommentStatus::Hidden->value,
        'expectedRevision' => $updated->revision,
        'reason' => 'Management route moderation',
        'pinned' => true,
    ])->assertOk()->assertJsonPath('data.revision', 3);

    $this->actingAs($moderator)->getJson(route(
        'nvl.comments.management.attachments.index',
        ['comment' => $comment->id],
    ))->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($moderator)->deleteJson(route(
        'nvl.comments.management.attachments.destroy',
        ['comment' => $comment->id, 'association' => $association->id],
    ))->assertNoContent();

    $revisions = $this->actingAs($moderator)->getJson(route(
        'nvl.comments.management.revisions.index',
        ['comment' => $comment->id],
    ))->assertOk()->assertJsonCount(1, 'data');
    $revisionId = $revisions->json('data.0.id');

    expect($revisionId)->toBeString();

    $revisionRestored = $this->actingAs($moderator)->postJson(route(
        'nvl.comments.management.revisions.restore',
        ['comment' => $comment->id, 'revision' => $revisionId],
    ), [
        'expectedRevision' => $moderated->json('data.revision'),
    ])->assertOk()->assertJsonPath('data.revision', 4);

    $this->actingAs($moderator)->getJson(route(
        'nvl.comments.management.reports.index',
        ['comment' => $comment->id],
    ))->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($moderator)->putJson(route(
        'nvl.comments.management.reports.resolve',
        ['report' => $report->id],
    ), [
        'status' => CommentReportStatus::Resolved->value,
        'expectedRevision' => $comment->refresh()->revision,
        'resolution' => 'Management route resolved',
    ])->assertOk()->assertJsonPath('data.status', CommentReportStatus::Resolved->value);

    app(DeleteCommentAction::class)->execute(
        $comment,
        new DeleteCommentData($comment->refresh()->revision),
        $author,
        CommentAudience::Member,
    );
    $deleted = Comment::query()->withTrashed()->findOrFail($comment->id);
    $restored = $this->actingAs($moderator)->postJson(route(
        'nvl.comments.management.restore',
        ['comment' => $comment->id],
    ), [
        'expectedRevision' => $deleted->revision,
    ])->assertOk()->assertJsonPath('data.revision', 7);

    $this->actingAs($moderator)->postJson(route(
        'nvl.comments.management.anonymize',
        ['comment' => $comment->id],
    ), [
        'expectedRevision' => $restored->json('data.revision'),
        'reason' => 'Management route anonymization',
    ])->assertOk()
        ->assertJsonPath('data.deleted', true)
        ->assertJsonPath('data.anonymizationReason', 'Management route anonymization')
        ->assertJsonMissingPath('data.body');
});
