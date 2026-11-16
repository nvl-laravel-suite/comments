<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Exceptions\CommentIdempotencyConflictException;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentReaction;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Services\CommentHistoryMover;
use Nvl\Comments\Services\CommentModeration;
use Nvl\Comments\Services\CommentReader;
use Nvl\Comments\Services\CommentSchemaAdopter;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Services\HtmlCommentCodec;
use Nvl\Comments\Services\RichBlockCommentCodec;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\NativeModerationPolicy;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Support\Enums\PublicActor;
use Nvl\Support\Enums\SystemActor;

it('denies system moderation without an explicit host policy grant', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Review me'], $actor, CommentAudience::Public);

    app(CommentModeration::class)->moderate($comment, [
        'status' => CommentStatus::Rejected,
        'expected_revision' => $comment->revision,
    ], SystemActor::Background);
})->throws(ModelNotFoundException::class);

it('accepts a background moderator only through an explicit host policy', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Review me'], $actor, CommentAudience::Public);
    $policy = new NativeModerationPolicy;
    app()->instance(CommentAuthorization::class, $policy);
    app()->instance(CommentQueryScope::class, $policy);

    $updated = app(CommentModeration::class)->moderate($comment, [
        'status' => CommentStatus::Rejected,
        'expected_revision' => $comment->revision,
    ], SystemActor::Background);

    expect($updated->status)->toBe(CommentStatus::Rejected);
    expect($updated->moderated_by_type)->toBe('system');
});

it('reports idempotently and resolves through explicit moderation authority', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $author = NativeCommentActor::create(['name' => 'Author']);
    $reporter = NativeCommentActor::create(['name' => 'Reporter']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Review me'], $author, CommentAudience::Public);
    $moderation = app(CommentModeration::class);

    $first = $moderation->report($comment, ['reason' => 'Abuse'], $reporter);
    $again = $moderation->report($comment, ['reason' => 'Abuse'], $reporter);
    expect($again->id)->toBe($first->id);
    expect($comment->fresh()?->open_report_count)->toBe(1);

    $policy = new NativeModerationPolicy;
    app()->instance(CommentAuthorization::class, $policy);
    app()->instance(CommentQueryScope::class, $policy);
    $resolved = app(CommentModeration::class)->resolveReport($first, [
        'status' => CommentReportStatus::Resolved,
        'expected_revision' => $comment->fresh()?->revision,
        'resolution' => 'Reviewed',
    ], SystemActor::Background);

    expect($resolved->status)->toBe(CommentReportStatus::Resolved);
    expect($comment->fresh()?->open_report_count)->toBe(0);
    $rows = app(CommentReader::class)->reports($comment, SystemActor::Background)->items();
    expect($rows)->toHaveCount(1);
    expect($rows[0])->not->toHaveKey('reporterId');
});

it('keeps separate approval and pin attribution and hides flagged rows outside management', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $author = NativeCommentActor::create(['name' => 'Author']);
    $pinner = NativeCommentActor::create(['name' => 'Pinner']);
    config(['nvl-comments.moderation.new_status' => 'pending']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Review me'], $author);

    $policy = new class implements CommentAuthorization, CommentQueryScope
    {
        public function allows(CommentAbility $ability, Authenticatable|SystemActor|PublicActor $actor, ?Comment $comment = null, ?Model $target = null, CommentAudience $audience = CommentAudience::Public, array $context = []): bool
        {
            return true;
        }

        public function scopeComments(Builder $query, Authenticatable|SystemActor|PublicActor $actor, Model $target, CommentAudience $audience, CommentAbility $ability): void {}
    };
    app()->instance(CommentAuthorization::class, $policy);
    app()->instance(CommentQueryScope::class, $policy);
    $moderation = app(CommentModeration::class);

    $approved = $moderation->moderate($comment, ['status' => CommentStatus::Approved, 'expected_revision' => $comment->revision], SystemActor::Background);
    expect($approved->approved_by_type)->toBe('system')->and($approved->approved_at)->not->toBeNull()
        ->and($approved->pinned_at)->toBeNull();

    $pinned = $moderation->moderate($approved, ['status' => CommentStatus::Approved, 'pinned' => true, 'expected_revision' => $approved->revision], $pinner);
    expect($pinned->pinned_by)->toBe((string) $pinner->getKey())->and($pinned->pinned_at)->not->toBeNull()
        ->and($pinned->approved_by_type)->toBe('system')->and($pinned->approved_at)->toEqual($approved->approved_at);

    $flagged = $moderation->moderate($pinned, ['status' => CommentStatus::Flagged, 'pinned' => false, 'expected_revision' => $pinned->revision], $pinner);
    expect($flagged->pinned_by)->toBeNull()->and($flagged->pinned_at)->toBeNull()
        ->and($flagged->approved_at)->toEqual($approved->approved_at);
    $reader = app(CommentReader::class);
    expect($reader->latest($target, $author, CommentAudience::Member))->toBeNull()
        ->and($reader->latest($target, PublicActor::Anonymous, CommentAudience::Public))->toBeNull()
        ->and($reader->latest($target, $pinner, CommentAudience::Management)['id'])->toBe($comment->id);
});

it('member may flag but cannot approve, hide or pin', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $author = NativeCommentActor::create(['name' => 'Author']);
    $member = NativeCommentActor::create(['name' => 'Member']);
    $comment = app(CommentWriter::class)->create($target, ['body' => 'Review me'], $author);
    $policy = new class implements CommentAuthorization, CommentQueryScope
    {
        public function allows(CommentAbility $ability, Authenticatable|SystemActor|PublicActor $actor, ?Comment $comment = null, ?Model $target = null, CommentAudience $audience = CommentAudience::Public, array $context = []): bool
        {
            return $ability === CommentAbility::Flag;
        }

        public function scopeComments(Builder $query, Authenticatable|SystemActor|PublicActor $actor, Model $target, CommentAudience $audience, CommentAbility $ability): void {}
    };
    app()->instance(CommentAuthorization::class, $policy);
    app()->instance(CommentQueryScope::class, $policy);
    $moderation = app(CommentModeration::class);

    foreach ([['status' => CommentStatus::Approved], ['status' => CommentStatus::Hidden], ['status' => CommentStatus::Flagged, 'pinned' => true]] as $transition) {
        expect(fn () => $moderation->moderate($comment, [...$transition, 'expected_revision' => $comment->revision], $member))
            ->toThrow(AuthorizationException::class);
    }
    $flagged = $moderation->moderate($comment, ['status' => CommentStatus::Flagged, 'reason' => 'Spam?', 'expected_revision' => $comment->revision], $member);

    expect($flagged->status)->toBe(CommentStatus::Flagged)
        ->and($flagged->moderated_by)->toBe((string) $member->getKey())
        ->and($flagged->is_pinned)->toBeFalse();
});

it('moves complete histories simultaneously within the caller transaction', function (): void {
    config(['nvl-core.owners' => [TestCommentTarget::class]]);
    $source = TestCommentTarget::create(['name' => 'First']);
    $target = TestCommentTarget::create(['name' => 'Second']);
    $last = TestCommentTarget::create(['name' => 'Last']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    $root = $writer->create($source, ['body' => 'Root'], $actor);
    $child = $writer->create($source, ['body' => 'Reply', 'parentId' => $root->id], $actor);
    $child->delete();
    $existing = $writer->create($target, ['body' => 'Existing'], $actor);
    $type = $source->getMorphClass();
    $targets = [$source->id => $target->id, $target->id => $last->id];
    $move = fn () => app(CommentHistoryMover::class)->moveMany($actor, $type, $type, $targets);

    expect($move)->toThrow(AuthorizationException::class);
    app()->instance(CommentAuthorization::class, new class implements CommentAuthorization
    {
        public function allows(CommentAbility $ability, Authenticatable|SystemActor|PublicActor $actor, ?Comment $comment = null, ?Model $target = null, CommentAudience $audience = CommentAudience::Public, array $context = []): bool
        {
            return $ability === CommentAbility::MoveHistory && $audience === CommentAudience::Management && isset($context['direction']);
        }
    });

    expect(fn () => DB::transaction(function () use ($move, $source, $target): void {
        expect($move())->toBe([$source->id => 2, $target->id => 1]);

        throw new RuntimeException('Rollback owner merge');
    }))->toThrow(RuntimeException::class, 'Rollback owner merge');
    expect($root->fresh()->commentable_id)->toBe($source->id);

    expect($move())->toBe([$source->id => 2, $target->id => 1]);
    expect($root->fresh()->commentable_id)->toBe($target->id)
        ->and($existing->fresh()->commentable_id)->toBe($last->id)
        ->and(Comment::withTrashed()->findOrFail($child->id))->parent_id->toBe($root->id)
        ->commentable_identity_hash->toBe(CommentIdentity::pair($type, $target->id))->deleted_at->not->toBeNull();
});

it('keyed replay cannot reveal moved history', function (): void {
    config(['nvl-core.owners' => [TestCommentTarget::class]]);
    $source = TestCommentTarget::create(['name' => 'Source']);
    $destination = TestCommentTarget::create(['name' => 'Destination']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    $key = (string) Str::uuid();
    $comment = $writer->create($source, ['body' => 'Moved', 'idempotency_key' => $key], $actor);
    app()->instance(CommentAuthorization::class, new class implements CommentAuthorization
    {
        public function allows(CommentAbility $ability, Authenticatable|SystemActor|PublicActor $actor, ?Comment $comment = null, ?Model $target = null, CommentAudience $audience = CommentAudience::Public, array $context = []): bool
        {
            return true;
        }
    });
    app(CommentHistoryMover::class)->moveMany($actor, $source->getMorphClass(), $destination->getMorphClass(), [$source->id => $destination->id]);

    expect(fn () => $writer->create($source, ['body' => 'Moved', 'idempotency_key' => $key], $actor))
        ->toThrow(CommentIdempotencyConflictException::class);
    expect($comment->fresh()->commentable_id)->toBe($destination->id);
});

it('keeps versioned source content in revision history and exposes only its text projection', function (): void {
    $target = TestCommentTarget::create(['name' => 'Article']);
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $writer = app(CommentWriter::class);
    $html = '<p>Hello &amp; <strong>team</strong></p>';
    $codec = new HtmlCommentCodec;
    $key = (string) Str::uuid();
    $comment = $writer->createEncoded($target, ['idempotency_key' => $key], $codec, $html, $actor);
    expect($comment->body)->toBe('Hello & team')
        ->and($comment->document)->toBe(['codec' => 'html', 'version' => 1, 'payload' => $html]);
    expect($writer->createEncoded($target, ['idempotency_key' => $key], $codec, $html, $actor)->id)->toBe($comment->id);
    expect(fn () => $writer->createEncoded($target, ['idempotency_key' => $key], $codec, '<p>Hello &amp; team</p>', $actor))
        ->toThrow(CommentIdempotencyConflictException::class);
    expect(app(CommentReader::class)->latest($target, $actor, CommentAudience::Member)['document'])->toBeNull();

    $blocks = ['blocks' => [['type' => 'heading', 'content' => [['type' => 'text', 'text' => 'Revision']]]]];
    $updated = $writer->updateEncoded($comment, ['expected_revision' => $comment->revision], new RichBlockCommentCodec, $blocks, $actor);
    $revision = CommentRevision::query()->where('comment_id', $comment->id)->sole();
    expect($updated->body)->toBe('Revision')->and($updated->document['payload'])->toBe($blocks)
        ->and($revision->document['payload'])->toBe($html);

    expect(fn () => $writer->updateEncoded($updated, ['expected_revision' => $updated->revision], $codec, '<script>alert(1)</script><p>Other</p>', $actor))
        ->toThrow(InvalidCommentMutationException::class, 'script');
    expect($updated->fresh()->body)->toBe('Revision');
    $restored = $writer->restoreRevision($updated, $revision, $updated->revision, $actor);
    expect($restored->body)->toBe('Hello & team')->and($restored->document['payload'])->toBe($html);
});

it('stages legacy comments with original thread IDs, reactions and moderation facts', function (): void {
    DB::connection()->commit();
    Schema::create('comments', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuidMorphs('commentable');
        $table->text('content');
        $table->string('content_type');
        foreach (['user_id', 'parent_id', 'pinned_by', 'approved_by', 'edited_by'] as $field) {
            $table->uuid($field)->nullable();
        }
        foreach (['user_provider', 'tags', 'metadata'] as $field) {
            $table->text($field)->nullable();
        }
        foreach (['pinned_at', 'approved_at', 'last_edited_at'] as $field) {
            $table->timestamp($field)->nullable();
        }
        $table->boolean('is_pinned')->default(false);
        $table->boolean('is_edited')->default(false);
        $table->string('status');
        $table->integer('reactions_count')->default(0);
        $table->integer('replies_count')->default(0);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('comments_reactions', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('comment_id');
        $table->uuid('user_id');
        $table->string('type');
        $table->timestamps();
    });
    config(['nvl-core.owners' => [TestCommentTarget::class]]);
    $target = TestCommentTarget::create(['name' => 'Legacy article']);
    $root = (string) Str::uuid();
    $child = (string) Str::uuid();
    $user = (string) Str::uuid();
    $reactionId = (string) Str::uuid();
    $at = '2026-08-01 15:30:00';
    $fields = ['commentable_type' => 'old-article', 'commentable_id' => $target->id, 'user_id' => $user, 'created_at' => $at, 'updated_at' => $at];
    app()->instance(CommentAuthorization::class, new class implements CommentAuthorization
    {
        public function allows(CommentAbility $ability, Authenticatable|SystemActor|PublicActor $actor, ?Comment $comment = null, ?Model $target = null, CommentAudience $audience = CommentAudience::Public, array $context = []): bool
        {
            return $actor === SystemActor::Background && $ability === CommentAbility::AdoptHistory;
        }
    });

    try {
        DB::table('comments')->insert($fields + ['id' => $root, 'content' => '<p>First</p>', 'content_type' => 'html', 'status' => 'active', 'approved_by' => $user, 'approved_at' => $at, 'edited_by' => $user, 'last_edited_at' => $at]);
        DB::table('comments')->insert($fields + ['id' => $child, 'parent_id' => $root, 'content' => 'Reply', 'content_type' => 'text', 'status' => 'flagged', 'deleted_at' => $at]);
        DB::table('comments_reactions')->insert(['id' => $reactionId, 'comment_id' => $root, 'user_id' => $user, 'type' => 'love', 'created_at' => $at, 'updated_at' => $at]);
        $adopter = app(CommentSchemaAdopter::class);
        $source = ['actor_type' => 'legacy-user', 'owner_types' => ['old-article' => TestCommentTarget::class]];
        $actor = SystemActor::Background;
        $duplicate = (string) Str::uuid();
        DB::table('comments_reactions')->insert(['id' => $duplicate, 'comment_id' => $root, 'user_id' => $user, 'type' => 'love']);
        $conflicted = $adopter->plan($actor, $source);
        expect($conflicted['conflicts'][0])->toContain($reactionId, $duplicate);
        expect(fn () => $adopter->apply($actor, $conflicted['fingerprint'], $source))->toThrow(InvalidCommentMutationException::class, 'adoption conflicts');
        expect(Comment::withTrashed()->whereKey([$root, $child])->count())->toBe(0);
        DB::table('comments_reactions')->where('id', $duplicate)->delete();

        $plan = $adopter->plan($actor, $source);
        expect($plan)->toMatchArray(['comments' => 2, 'reactions' => 1, 'staged_comments' => 0, 'staged_reactions' => 0, 'conflicts' => []]);
        expect(fn () => $adopter->verify($actor, $plan['fingerprint'], $source))->toThrow(InvalidCommentMutationException::class, 'not fully staged');
        $adopter->apply($actor, $plan['fingerprint'], $source);
        expect($adopter->verify($actor, $plan['fingerprint'], $source))->toBe($adopter->apply($actor, $plan['fingerprint'], $source));

        $comment = Comment::findOrFail($root);
        expect($comment->body)->toBe('First')->and($comment->approved_by)->toBe($user)->and($comment->edited_by)->toBe($user)
            ->and($comment->created_at->toDateTimeString())->toBe($at)->and($comment->reaction_count)->toBe(1)
            ->and(Comment::withTrashed()->findOrFail($child))->root_id->toBe($root)->status->toBe(CommentStatus::Flagged)
            ->and(CommentReaction::findOrFail($reactionId))->comment_id->toBe($root);

        DB::table('comments')->where('id', $child)->update(['content' => 'Changed since review']);
        expect(fn () => $adopter->verify($actor, $plan['fingerprint'], $source))->toThrow(InvalidCommentMutationException::class, 'changed');
    } finally {
        Comment::withTrashed()->whereKey($root)->forceDelete();
        Schema::dropIfExists('comments_reactions');
        Schema::dropIfExists('comments');
        $target->delete();
    }
});
