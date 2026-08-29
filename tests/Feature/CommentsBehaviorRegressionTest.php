<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Comments\Actions\AnonymizeCommentAction;
use Nvl\Comments\Actions\AttachCommentMediaAction;
use Nvl\Comments\Actions\CreateCommentAction;
use Nvl\Comments\Actions\DeleteCommentAction;
use Nvl\Comments\Actions\ListCommentAttachmentsAction;
use Nvl\Comments\Actions\ListCommentsAction;
use Nvl\Comments\Actions\ListModerationCommentsAction;
use Nvl\Comments\Actions\ListTargetCommentReportsAction;
use Nvl\Comments\Actions\ModerateCommentAction;
use Nvl\Comments\Actions\ReportCommentAction;
use Nvl\Comments\Actions\ResolveCommentReportAction;
use Nvl\Comments\Actions\SetCommentReactionAction;
use Nvl\Comments\Actions\UpdateCommentAction;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\AnonymizeCommentData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Data\Mutations\DeleteCommentData;
use Nvl\Comments\Data\Mutations\ModerateCommentData;
use Nvl\Comments\Data\Mutations\ReportCommentData;
use Nvl\Comments\Data\Mutations\ResolveCommentReportData;
use Nvl\Comments\Data\Mutations\UpdateCommentData;
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Comments\Exceptions\CommentTargetNotFoundException;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Exceptions\StaleCommentException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentContentGuard;
use Nvl\Comments\Services\CommentProjectionFactory;
use Nvl\Comments\Services\CommentStateReconciler;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Comments\Tests\Fixtures\TestIntegerCommentTarget;
use Nvl\Comments\Tests\Fixtures\TestStringCommentTarget;
use Nvl\Filterable\Data\FilterCriterion;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Data\SortCriterion;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Enums\SortDirection;
use Nvl\Filterable\Exceptions\FilterableException;
use Nvl\Media\Contracts\AttachMediaContract;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;

it('soft deletes a reply while preserving descendants and correcting its parent counter', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $actor = new CommentActorData('member', '42');
    $create = app(CreateCommentAction::class);
    $root = $create->execute($target, new CreateCommentData('Root'), $actor);
    $reply = $create->execute(
        $target,
        new CreateCommentData('Reply', parentId: $root->id),
        $actor,
    );
    $descendant = $create->execute(
        $target,
        new CreateCommentData('Descendant', parentId: $reply->id),
        $actor,
    );

    $deleted = app(DeleteCommentAction::class)->execute(
        $reply,
        new DeleteCommentData($reply->revision),
        $actor,
    );
    $deletedReply = Comment::query()->withTrashed()->findOrFail($reply->id);

    expect($deleted)->toBeTrue()
        ->and($root->refresh()->reply_count)->toBe(0)
        ->and($deletedReply->trashed())->toBeTrue()
        ->and($deletedReply->replies()->pluck('id')->all())->toBe([$descendant->id])
        ->and($descendant->refresh()->parent_id)->toBe($reply->id);

    $this->assertSoftDeleted($reply);
    $this->assertModelExists($descendant);
});

it('rejects stale deletion without changing the comment', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $actor = new CommentActorData('member', '42');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Keep me'),
        $actor,
    );

    expect(fn () => app(DeleteCommentAction::class)->execute(
        $comment,
        new DeleteCommentData($comment->revision + 1),
        $actor,
    ))->toThrow(StaleCommentException::class);

    $this->assertModelExists($comment);
    expect($comment->refresh()->trashed())->toBeFalse();
});

it('removes reactions idempotently without allowing the counter to drift', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $actor = new CommentActorData('member', '42');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('React to me'),
        $actor,
    );
    $reactions = app(SetCommentReactionAction::class);
    $active = $reactions->execute($comment, 'helpful', true, $actor);

    expect($active)->not->toBeNull()
        ->and($comment->refresh()->reaction_count)->toBe(1)
        ->and($comment->reactions()->count())->toBe(1);

    $removed = $reactions->execute($comment, 'helpful', false, $actor);

    expect($removed)->toBeNull()
        ->and($comment->refresh()->reaction_count)->toBe(0)
        ->and($comment->reactions()->count())->toBe(0);

    $same = $reactions->execute($comment, 'helpful', false, $actor);

    expect($same)->toBeNull()
        ->and($comment->refresh()->reaction_count)->toBe(0)
        ->and($comment->reactions()->count())->toBe(0);
});

it('resolves persisted comments through integer and string target relationships', function (): void {
    Schema::create('comment_integer_targets', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('comment_string_targets', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->timestamps();
    });

    $integerTarget = TestIntegerCommentTarget::query()->create(['name' => 'Integer target']);
    $stringTarget = TestStringCommentTarget::query()->create([
        'id' => 'article:guide',
        'name' => 'String target',
    ]);
    $actor = new CommentActorData('member', '42');
    $create = app(CreateCommentAction::class);
    $integerComment = $create->execute(
        $integerTarget,
        new CreateCommentData('Integer comment'),
        $actor,
    );
    $stringComment = $create->execute(
        $stringTarget,
        new CreateCommentData('String comment'),
        $actor,
    );

    $loadedIntegerTarget = TestIntegerCommentTarget::query()
        ->with('comments')
        ->findOrFail($integerTarget->id);
    $loadedStringTarget = TestStringCommentTarget::query()
        ->with('comments')
        ->findOrFail($stringTarget->id);
    $integerMatches = TestIntegerCommentTarget::query()
        ->whereHas('comments', fn ($query) => $query->whereKey($integerComment->id))
        ->pluck('id')
        ->all();
    $stringMatches = TestStringCommentTarget::query()
        ->whereHas('comments', fn ($query) => $query->whereKey($stringComment->id))
        ->pluck('id')
        ->all();

    expect($integerComment->commentable_id)->toBe((string) $integerTarget->id)
        ->and($stringComment->commentable_id)->toBe($stringTarget->id)
        ->and($loadedIntegerTarget->comments->modelKeys())->toBe([$integerComment->id])
        ->and($loadedStringTarget->comments->modelKeys())->toBe([$stringComment->id])
        ->and($integerMatches)->toBe([$integerTarget->id])
        ->and($stringMatches)->toBe([$stringTarget->id]);
});

it('isolates exact target actor reaction and report identities', function (): void {
    $driver = DB::connection()->getDriverName();

    Schema::create('comment_string_targets', function (Blueprint $table) use ($driver): void {
        $identifier = $table->string('id');

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $identifier->collation('utf8mb4_bin');
        }

        $identifier->primary();
        $table->string('name');
        $table->timestamps();
    });

    $upperTarget = TestStringCommentTarget::query()->create([
        'id' => 'TenantA',
        'name' => 'Upper tenant',
    ]);
    $lowerTarget = TestStringCommentTarget::query()->create([
        'id' => 'tenanta',
        'name' => 'Lower tenant',
    ]);
    $upperActor = new CommentActorData('member', 'Alice');
    $lowerActor = new CommentActorData('member', 'alice');
    $spacedActor = new CommentActorData('member', 'Alice ');
    $create = app(CreateCommentAction::class);
    $upperComment = $create->execute(
        $upperTarget,
        new CreateCommentData('Upper private', visibility: CommentVisibility::Private),
        $upperActor,
        CommentAudience::Member,
    );
    $lowerComment = $create->execute(
        $lowerTarget,
        new CreateCommentData('Lower private', visibility: CommentVisibility::Private),
        $lowerActor,
        CommentAudience::Member,
    );
    $upperRows = app(ListCommentsAction::class)->execute(
        $upperTarget,
        $upperActor,
        FilterSet::none(),
        audience: CommentAudience::Member,
    );
    $lowerRows = app(ListCommentsAction::class)->execute(
        $lowerTarget,
        $lowerActor,
        FilterSet::none(),
        audience: CommentAudience::Member,
    );
    $publicComment = $create->execute(
        $upperTarget,
        new CreateCommentData('Public identity boundary'),
        $upperActor,
    );
    $reactions = app(SetCommentReactionAction::class);
    $reports = app(ReportCommentAction::class);

    foreach ([$upperActor, $lowerActor, $spacedActor] as $actor) {
        $reactions->execute($publicComment, 'helpful', true, $actor);
        $reports->execute($publicComment, new ReportCommentData('spam'), $actor);
    }
    $upperRelationshipMatches = TestStringCommentTarget::query()
        ->whereHas('comments', fn ($query) => $query->whereKey($upperComment->id))
        ->pluck('id')
        ->all();
    $lowerRelationshipMatches = TestStringCommentTarget::query()
        ->whereHas('comments', fn ($query) => $query->whereKey($lowerComment->id))
        ->pluck('id')
        ->all();

    expect($upperRows->pluck('id')->all())->toBe([$upperComment->id])
        ->and($lowerRows->pluck('id')->all())->toBe([$lowerComment->id])
        ->and($upperRelationshipMatches)->toBe([$upperTarget->id])
        ->and($lowerRelationshipMatches)->toBe([$lowerTarget->id])
        ->and($publicComment->refresh()->reaction_count)->toBe(3)
        ->and($publicComment->report_count)->toBe(3)
        ->and($publicComment->reactions()->count())->toBe(3)
        ->and($publicComment->reports()->count())->toBe(3)
        ->and($upperComment->commentable_identity_hash)->not->toBe(
            $lowerComment->commentable_identity_hash,
        )
        ->and($publicComment->reactions()->pluck('actor_identity_hash')->unique()->count())->toBe(3)
        ->and($publicComment->reports()->pluck('reporter_identity_hash')->unique()->count())->toBe(3);
});

it('fails closed for noncanonical imported lifecycle classifications', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Imported classification'),
        new CommentActorData('member', '42'),
    );
    DB::table(CommentsTables::Comments)->where('id', $comment->id)->update([
        'status' => 'APPROVED',
        'status_hash' => CommentIdentity::value('comment-status', 'APPROVED'),
    ]);

    $statusRows = app(ListCommentsAction::class)->execute(
        $target,
        CommentActorData::anonymous(),
        FilterSet::none(),
    );

    DB::table(CommentsTables::Comments)->where('id', $comment->id)->update([
        'status' => CommentStatus::Approved->value,
        'status_hash' => CommentIdentity::value(
            'comment-status',
            CommentStatus::Approved,
        ),
        'visibility' => 'PUBLIC',
        'visibility_hash' => CommentIdentity::value('comment-visibility', 'PUBLIC'),
    ]);

    $visibilityRows = app(ListCommentsAction::class)->execute(
        $target,
        CommentActorData::anonymous(),
        FilterSet::none(),
    );

    expect($statusRows->total())->toBe(0)
        ->and($visibilityRows->total())->toBe(0);
});

it('persists and queries the canonical key from a dirty integer target', function (): void {
    Schema::create('comment_integer_targets', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $canonicalTarget = TestIntegerCommentTarget::query()->create(['name' => 'Canonical']);
    $dirtyTarget = new TestIntegerCommentTarget;
    $dirtyTarget->forceFill(['id' => '01', 'name' => 'Untrusted caller state']);
    $dirtyTarget->exists = true;
    $comment = app(CreateCommentAction::class)->execute(
        $dirtyTarget,
        new CreateCommentData('Canonical key'),
        new CommentActorData('member', '42'),
    );
    app(ReportCommentAction::class)->execute(
        $comment,
        new ReportCommentData('spam'),
        new CommentActorData('member', '84'),
    );
    $moderation = app(ListModerationCommentsAction::class)->execute(
        $dirtyTarget,
        CommentActorData::system(),
    );
    $reports = app(ListTargetCommentReportsAction::class)->execute(
        $dirtyTarget,
        CommentActorData::system(),
    );

    expect($comment->commentable_id)->toBe((string) $canonicalTarget->id)
        ->and($comment->commentable_id)->not->toBe('01')
        ->and($moderation->pluck('id')->all())->toBe([$comment->id])
        ->and($reports->total())->toBe(1);
});

it('classifies malformed imported integer targets as drift without issuing a cast query', function (): void {
    Schema::create('comment_integer_targets', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $target = TestIntegerCommentTarget::query()->create(['name' => 'Canonical']);
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Imported row'),
        new CommentActorData('member', '42'),
    );
    DB::table(CommentsTables::Comments)->where('id', $comment->id)->update([
        'commentable_id' => 'not-an-integer',
        'commentable_identity_hash' => CommentIdentity::pair(
            $comment->commentable_type,
            'not-an-integer',
        ),
    ]);
    $comment = Comment::query()->findOrFail($comment->id);

    expect(fn () => app(CommentTargetLocator::class)->locate($comment))
        ->toThrow(CommentTargetNotFoundException::class);

    $result = app(CommentStateReconciler::class)->reconcile(null, 100);

    expect($result->missingTargetComments)->toBe(1)
        ->and($result->healthy)->toBeFalse();
});

it('fails reconciliation safely for stale identity fingerprints without repairing derived counters', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Imported identities']);
    $author = new CommentActorData('member', '42');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Fingerprint audit'),
        $author,
    );
    $reaction = app(SetCommentReactionAction::class)->execute(
        $comment,
        'helpful',
        true,
        $author,
    );
    $report = app(ReportCommentAction::class)->execute(
        $comment,
        new ReportCommentData('spam'),
        new CommentActorData('member', '84'),
    );
    $invalidHash = str_repeat('0', 64);

    DB::table(CommentsTables::Comments)->where('id', $comment->id)->update([
        'status_hash' => $invalidHash,
    ]);
    DB::table(CommentsTables::Reactions)->where('id', $reaction?->id)->update([
        'actor_identity_hash' => $invalidHash,
    ]);
    DB::table(CommentsTables::Reports)->where('id', $report->id)->update([
        'status_hash' => $invalidHash,
    ]);

    $dryRun = app(CommentStateReconciler::class)->reconcile(null, 100);
    $repair = app(CommentStateReconciler::class)->reconcile(null, 100, true);

    expect($dryRun->identityFingerprintMismatches)->toBe(3)
        ->and($dryRun->openReportCountMismatches)->toBe(1)
        ->and($dryRun->drifted)->toBe(1)
        ->and($dryRun->healthy)->toBeFalse()
        ->and($repair->identityFingerprintMismatches)->toBe(3)
        ->and($repair->repaired)->toBe(0)
        ->and($repair->remaining)->toBe(1)
        ->and($comment->refresh()->open_report_count)->toBe(1);
});

it('applies allowlisted comment filters and rejects unknown aliases', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $actor = new CommentActorData('member', '42');
    $create = app(CreateCommentAction::class);
    $english = $create->execute(
        $target,
        new CreateCommentData('English', locale: 'en'),
        $actor,
    );
    $french = $create->execute(
        $target,
        new CreateCommentData('French', locale: 'fr'),
        $actor,
    );
    $filters = new FilterSet(filters: [
        new FilterCriterion('status', FilterOperator::Equals, CommentStatus::Approved->value),
        new FilterCriterion('locale', FilterOperator::Equals, 'fr'),
    ]);
    $comments = app(ListCommentsAction::class)->execute(
        $target,
        CommentActorData::anonymous(),
        $filters,
    );

    expect($comments->total())->toBe(1)
        ->and($comments->items()[0]->id)->toBe($french->id)
        ->and($comments->items()[0]->id)->not->toBe($english->id);

    expect(fn () => app(ListCommentsAction::class)->execute(
        $target,
        CommentActorData::anonymous(),
        new FilterSet([
            new FilterCriterion('body', FilterOperator::Equals, 'French'),
        ]),
    ))->toThrow(FilterableException::class, 'Unknown filter alias');
});

it('lets caller sorting control pinned order while retaining pin-first defaults', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $actor = new CommentActorData('member', '42');
    $create = app(CreateCommentAction::class);
    $first = $create->execute($target, new CreateCommentData('First'), $actor);
    $second = $create->execute($target, new CreateCommentData('Second'), $actor);
    $pinned = $create->execute($target, new CreateCommentData('Pinned'), $actor);
    $pinned = app(ModerateCommentAction::class)->execute(
        $pinned,
        new ModerateCommentData(
            CommentStatus::Approved,
            $pinned->revision,
            pinned: true,
        ),
        CommentActorData::system(),
    );
    $list = app(ListCommentsAction::class);
    $defaultOrder = $list->execute(
        $target,
        CommentActorData::anonymous(),
        FilterSet::none(),
    );
    $ascendingPinned = $list->execute(
        $target,
        CommentActorData::anonymous(),
        new FilterSet(sorts: [
            new SortCriterion('pinned', SortDirection::Asc),
            new SortCriterion('created', SortDirection::Asc),
        ]),
    );

    expect($defaultOrder->items()[0]->id)->toBe($pinned->id)
        ->and(array_slice($ascendingPinned->pluck('id')->all(), 0, 2))
        ->toContain($first->id, $second->id)
        ->and($ascendingPinned->items()[2]->id)->toBe($pinned->id);
});

it('keeps actor and metadata fields out of the public comment payload', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData(
            'Public body',
            tags: ['public-tag'],
            metadata: ['private' => 'private-metadata-value'],
        ),
        new CommentActorData('member', 'sensitive-actor-id'),
    );
    $payload = app(CommentProjectionFactory::class)
        ->publicComment($comment, $target)
        ->toArray();
    $privateKeys = [
        'actorType',
        'actorId',
        'metadata',
        'moderationReason',
        'moderatedByType',
        'moderatedBy',
        'reportCount',
        'status',
        'visibility',
    ];
    $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);

    expect($payload)->toHaveKeys([
        'id',
        'rootId',
        'parentId',
        'depth',
        'body',
        'format',
        'locale',
        'tags',
        'replyCount',
        'reactionCount',
        'pinned',
        'edited',
        'attachmentCount',
        'createdAt',
        'updatedAt',
    ])->and(array_intersect($privateKeys, array_keys($payload)))->toBe([])
        ->and($encodedPayload)->not->toContain('sensitive-actor-id')
        ->and($encodedPayload)->not->toContain('private-metadata-value');
});

it('preserves omitted editable fields while honoring explicit replacement values', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $actor = new CommentActorData('member', '42');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData(
            body: 'Original',
            format: CommentFormat::Markdown,
            locale: 'en-GB',
            tags: ['release', 'guide'],
            metadata: ['source' => 'import', 'featured' => true],
        ),
        $actor,
    );
    $update = app(UpdateCommentAction::class);

    $bodyOnly = $update->execute(
        $comment,
        new UpdateCommentData('Body-only edit', $comment->revision),
        $actor,
    );

    expect($bodyOnly->body)->toBe('Body-only edit')
        ->and($bodyOnly->format)->toBe(CommentFormat::Markdown)
        ->and($bodyOnly->locale)->toBe('en-GB')
        ->and($bodyOnly->tags)->toBe(['release', 'guide'])
        ->and($bodyOnly->metadata)->toBe(['featured' => true, 'source' => 'import'])
        ->and($bodyOnly->revision)->toBe(2);

    $cleared = $update->execute(
        $bodyOnly,
        new UpdateCommentData(
            body: 'Explicit replacement',
            expectedRevision: $bodyOnly->revision,
            format: CommentFormat::Plain,
            locale: null,
            tags: [],
            metadata: [],
        ),
        $actor,
    );

    expect($cleared->body)->toBe('Explicit replacement')
        ->and($cleared->format)->toBe(CommentFormat::Plain)
        ->and($cleared->locale)->toBeNull()
        ->and($cleared->tags)->toBe([])
        ->and($cleared->metadata)->toBe([])
        ->and($cleared->revision)->toBe(3);
});

it('rejects associative tags at the direct content guard boundary', function (): void {
    $associativeTags = new CreateCommentData(
        body: 'Tagged',
        tags: ['topic' => 'laravel'],
    );
    $blankTag = new CreateCommentData(
        body: 'Tagged',
        tags: ['   '],
    );
    $listMetadata = new CreateCommentData(
        body: 'Metadata',
        metadata: ['first', 'second'],
    );

    expect(fn () => app(CommentContentGuard::class)->create($associativeTags))
        ->toThrow(InvalidCommentMutationException::class, 'Comment tags must be a list.')
        ->and(fn () => app(CommentContentGuard::class)->create($blankTag))
        ->toThrow(InvalidCommentMutationException::class, 'Comment tags must be valid, non-blank')
        ->and(fn () => app(CommentContentGuard::class)->create($listMetadata))
        ->toThrow(InvalidCommentMutationException::class, 'Comment metadata must use string keys.');
});

it('refuses to create a comment for a stale caller-supplied target', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Deleted article']);

    TestCommentTarget::query()->whereKey($target->id)->delete();

    expect($target->exists)->toBeTrue()
        ->and(fn () => app(CreateCommentAction::class)->execute(
            $target,
            new CreateCommentData('Orphaned comment'),
            new CommentActorData('member', '42'),
        ))->toThrow(
            InvalidCommentMutationException::class,
            'Comments require a target that still exists.',
        )
        ->and(fn () => app(ListCommentsAction::class)->execute(
            $target,
            CommentActorData::anonymous(),
            FilterSet::none(),
        ))->toThrow(CommentTargetNotFoundException::class)
        ->and(Comment::query()->count())->toBe(0);
});

it('does not trust a caller-modified target connection during canonical lookup', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Canonical article']);
    $target->setConnection('caller-controlled-connection');
    $target->name = 'Caller-modified name';

    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Canonical target lookup'),
        new CommentActorData('member', '42'),
    );
    $comments = app(ListCommentsAction::class)->execute(
        $target,
        CommentActorData::anonymous(),
        FilterSet::none(),
    );

    expect($comment->commentable_id)->toBe($target->id)
        ->and($comments->total())->toBe(1);
});

it('uses the canonical comment for attachment authorization', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $attacker = new CommentActorData('member', '42');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Another author wrote this'),
        new CommentActorData('member', '84'),
    );
    $media = Media::factory()->create([
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => 'member',
        'uploaded_by' => '42',
    ]);
    $comment->actor_id = '42';

    expect(fn () => app(AttachCommentMediaAction::class)->execute(
        $comment,
        $media,
        $attacker,
    ))->toThrow(AuthorizationException::class);

    expect(Comment::query()->findOrFail($comment->id)->actor_id)->toBe('84')
        ->and($comment->media()->count())->toBe(0);
});

it('uses the canonical media for attachment authorization', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $author = new CommentActorData('member', '42');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('My comment'),
        $author,
    );
    $media = Media::factory()->create([
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => 'member',
        'uploaded_by' => '84',
    ]);
    $media->uploaded_by = '42';

    expect(fn () => app(AttachCommentMediaAction::class)->execute(
        $comment,
        $media,
        $author,
    ))->toThrow(AuthorizationException::class);

    expect(Media::query()->findOrFail($media->id)->uploaded_by)->toBe('84')
        ->and($comment->media()->count())->toBe(0);
});

it('rejects attachment media whose mime type is outside the comment slot', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $author = new CommentActorData('member', '42');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('No archives here'),
        $author,
    );
    $media = Media::factory()->create([
        'filename' => 'archive.zip',
        'extension' => 'zip',
        'mime_type' => 'application/zip',
        'type' => MediaType::ARCHIVE,
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => 'member',
        'uploaded_by' => '42',
    ]);

    expect(fn () => app(AttachCommentMediaAction::class)->execute(
        $comment,
        $media,
        $author,
    ))->toThrow(
        InvalidCommentMutationException::class,
        'Media type [application/zip] is not allowed for comment attachments.',
    );

    expect($comment->media()->count())->toBe(0);

    config()->set('comments.attachments.maximum_file_bytes', 100);
    $oversized = Media::factory()->create([
        'filename' => 'oversized.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'type' => MediaType::DOCUMENT,
        'size' => 101,
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => 'member',
        'uploaded_by' => '42',
    ]);

    expect(fn () => app(AttachCommentMediaAction::class)->execute(
        $comment,
        $oversized,
        $author,
    ))->toThrow(
        InvalidCommentMutationException::class,
        'attachment size limit',
    );
});

it('removes Media dependencies from ordinary flows when attachments are disabled', function (): void {
    config()->set('comments.attachments.enabled', false);
    $target = TestCommentTarget::query()->create(['name' => 'No-media article']);
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('No attachment dependency'),
        new CommentActorData('member', '42'),
    );
    Schema::dropIfExists((new MediaAssociation)->getTable());

    $projection = app(CommentProjectionFactory::class)->publicComment($comment, $target);
    $attachments = app(ListCommentAttachmentsAction::class)->execute(
        $comment,
        CommentActorData::anonymous(),
        CommentAudience::Public,
    );
    $reconciliation = app(CommentStateReconciler::class)->reconcile(null, 100);
    $anonymized = app(AnonymizeCommentAction::class)->execute(
        $comment,
        new AnonymizeCommentData($comment->revision, 'Retention boundary'),
        CommentActorData::system(),
        CommentAudience::Management,
    );

    expect($projection->attachmentCount)->toBe(0)
        ->and($attachments)->toBeEmpty()
        ->and($reconciliation->healthy)->toBeTrue()
        ->and($anonymized->anonymized_at)->not->toBeNull();
});

it('resolves a report after its comment has been soft deleted', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $author = new CommentActorData('member', '42');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Reported comment'),
        $author,
    );
    $report = app(ReportCommentAction::class)->execute(
        $comment,
        new ReportCommentData('abuse', 'Requires moderator review'),
        new CommentActorData('member', '84'),
    );

    app(DeleteCommentAction::class)->execute(
        $comment,
        new DeleteCommentData($comment->revision),
        $author,
    );

    $resolved = app(ResolveCommentReportAction::class)->execute(
        $report,
        new ResolveCommentReportData(
            CommentReportStatus::Resolved,
            $comment->refresh()->revision,
            'Reviewed after author deletion',
        ),
        CommentActorData::system(),
    );

    $this->assertSoftDeleted($comment);
    expect($resolved->status)->toBe(CommentReportStatus::Resolved)
        ->and($resolved->resolution)->toBe('Reviewed after author deletion')
        ->and($resolved->reviewed_by_type)->toBe('system')
        ->and($resolved->comment->trashed())->toBeTrue();
});

it('returns sanitized tombstones so public descendants retain their thread structure', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $author = new CommentActorData('member', '42');
    $create = app(CreateCommentAction::class);
    $root = $create->execute(
        $target,
        new CreateCommentData(
            body: 'Content that must disappear',
            locale: 'en',
            tags: ['sensitive-context'],
        ),
        $author,
    );
    $reply = $create->execute(
        $target,
        new CreateCommentData('Visible descendant', parentId: $root->id),
        $author,
    );

    app(DeleteCommentAction::class)->execute(
        $root,
        new DeleteCommentData($root->revision),
        $author,
    );

    $comments = app(ListCommentsAction::class)->execute(
        $target,
        CommentActorData::anonymous(),
        FilterSet::none(),
    );
    $tombstone = collect($comments->items())->firstWhere('id', $root->id);
    $visibleReply = collect($comments->items())->firstWhere('id', $reply->id);
    $payload = app(CommentProjectionFactory::class)
        ->publicComment($tombstone, $target)
        ->toArray();

    expect($comments->total())->toBe(2)
        ->and($tombstone)->toBeInstanceOf(Comment::class)
        ->and($visibleReply)->toBeInstanceOf(Comment::class)
        ->and($payload)->not->toHaveKeys([
            'body',
            'locale',
            'tags',
            'attachmentCount',
            'reactions',
            'author',
        ])
        ->and($visibleReply->parent_id)->toBe($root->id)
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))
        ->not->toContain('Content that must disappear')
        ->not->toContain('sensitive-context');
});

it('applies configured default maximum and reply pagination bounds', function (): void {
    config()->set([
        'comments.pagination.default' => 2,
        'comments.pagination.maximum' => 3,
        'comments.threading.maximum_replies_per_page' => 1,
    ]);
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $actor = new CommentActorData('member', '42');
    $create = app(CreateCommentAction::class);

    foreach (range(1, 5) as $number) {
        $create->execute($target, new CreateCommentData("Root {$number}"), $actor);
    }

    $root = $create->execute($target, new CreateCommentData('Thread root'), $actor);
    $create->execute(
        $target,
        new CreateCommentData('First reply', parentId: $root->id),
        $actor,
    );
    $create->execute(
        $target,
        new CreateCommentData('Second reply', parentId: $root->id),
        $actor,
    );

    $defaultPage = app(ListCommentsAction::class)->execute(
        $target,
        CommentActorData::anonymous(),
        FilterSet::none(),
    );
    $maximumPage = app(ListCommentsAction::class)->execute(
        $target,
        CommentActorData::anonymous(),
        FilterSet::none(),
        99,
    );
    $replyPage = app(ListCommentsAction::class)->execute(
        $target,
        CommentActorData::anonymous(),
        new FilterSet([
            new FilterCriterion('root', FilterOperator::Equals, $root->id),
        ]),
        99,
    );

    expect($defaultPage->perPage())->toBe(2)
        ->and($maximumPage->perPage())->toBe(3)
        ->and($replyPage->perPage())->toBe(1)
        ->and($replyPage->total())->toBe(2);
});

it('enforces workflow text limits for direct action callers', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $author = new CommentActorData('member', '42');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Review me'),
        $author,
    );

    expect(fn () => app(ReportCommentAction::class)->execute(
        $comment,
        new ReportCommentData(str_repeat('r', 101)),
        new CommentActorData('member', '84'),
    ))->toThrow(
        InvalidCommentMutationException::class,
        'Report reason may contain at most 100 characters.',
    );

    expect(fn () => app(ModerateCommentAction::class)->execute(
        $comment,
        new ModerateCommentData(
            CommentStatus::Approved,
            $comment->revision,
            str_repeat('m', 2_001),
        ),
        CommentActorData::system(),
    ))->toThrow(
        InvalidCommentMutationException::class,
        'Moderation reason may contain at most 2000 characters.',
    );

    $report = app(ReportCommentAction::class)->execute(
        $comment,
        new ReportCommentData('abuse'),
        new CommentActorData('member', '84'),
    );

    expect(fn () => app(ResolveCommentReportAction::class)->execute(
        $report,
        new ResolveCommentReportData(
            CommentReportStatus::Resolved,
            $comment->refresh()->revision,
            '   ',
        ),
        CommentActorData::system(),
    ))->toThrow(
        InvalidCommentMutationException::class,
        'Report resolution must contain valid, non-blank UTF-8 text.',
    );

    expect($comment->refresh()->revision)->toBe(1)
        ->and($comment->report_count)->toBe(1)
        ->and($report->refresh()->status)->toBe(CommentReportStatus::Open);
});

it('prevents an exclusive private attachment from being shared across comments', function (): void {
    $target = TestCommentTarget::query()->create(['name' => 'Article']);
    $author = new CommentActorData('member', '42');
    $create = app(CreateCommentAction::class);
    $first = $create->execute($target, new CreateCommentData('First'), $author);
    $second = $create->execute($target, new CreateCommentData('Second'), $author);
    $media = Media::factory()->create([
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => 'member',
        'uploaded_by' => '42',
    ]);
    $attach = app(AttachCommentMediaAction::class);

    $association = $attach->execute($first, $media, $author);
    $otherMedia = Media::factory()->create([
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => 'member',
        'uploaded_by' => '42',
    ]);
    app(AttachMediaContract::class)->execute($otherMedia, $second, 'other');
    $listed = app(ListCommentsAction::class)->execute(
        $target,
        CommentActorData::anonymous(),
        FilterSet::none(),
    );
    $listedFirst = collect($listed->items())->firstWhere('id', $first->id);
    $listedSecond = collect($listed->items())->firstWhere('id', $second->id);
    $projections = app(CommentProjectionFactory::class);

    expect($association->associable_id)->toBe($first->id)
        ->and($projections->memberComment(
            $listedFirst,
            $target,
            $author,
        )->attachmentCount)->toBe(1)
        ->and($projections->memberComment(
            $listedSecond,
            $target,
            $author,
        )->attachmentCount)->toBe(0)
        ->and(fn () => $attach->execute($second, $media, $author))
        ->toThrow(
            InvalidCommentMutationException::class,
            'Private comment attachments may not be shared with another owner.',
        )
        ->and($first->media()->count())->toBe(1)
        ->and($second->attachmentAssociations()->count())->toBe(0)
        ->and($second->media()->count())->toBe(1);
});
