<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\CommentDocumentData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Data\Mutations\CreateRichCommentData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Exceptions\CommentIdempotencyConflictException;
use Nvl\Comments\Exceptions\InvalidCommentLifecycleException;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Comments\Support\CommentTargetIdentifier;
use Nvl\Comments\ValueObjects\CommentMentionContext;

/**
 * Owns the reusable atomic creation workflow shared by plain and rich entrypoints.
 *
 * This service is the stable internal write boundary for both creation Actions, so it owns
 * the transaction and mutation lock that must remain identical across both public paths.
 */
final readonly class CommentCreationWriter
{
    /**
     * Create the shared comment creation writer.
     */
    public function __construct(
        private CommentAccessService $access,
        private CommentContentGuard $guard,
        private CommentDocumentNormalizer $documents,
        private CommentIdempotencyDigest $idempotency,
        private CommentLifecycleGuard $lifecycle,
        private CommentMentionWriter $mentions,
        private CommentMetadataIndexWriter $metadataIndex,
        private CommentMutationLock $mutationLock,
        private CommentReadService $reads,
    ) {}

    /**
     * Create one plain or Markdown comment through the shared lifecycle.
     */
    public function create(
        Model $target,
        CreateCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience,
    ): Comment {
        if ($data->format === CommentFormat::RichText) {
            throw new InvalidCommentMutationException(
                'Rich comments must be created through CreateRichCommentAction.',
            );
        }

        return $this->execute($target, $data, $actor, $audience);
    }

    /**
     * Create one rich comment through the shared lifecycle.
     */
    public function createRich(
        Model $target,
        CreateRichCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience,
    ): Comment {
        $document = $this->documents->normalizeUnresolved($data->document);

        return $this->execute(
            $target,
            $data,
            $actor,
            $audience,
            $document,
            $this->documents->canonicalJson($document),
        );
    }

    /**
     * Execute the common target, hierarchy, access, idempotency, and persistence lifecycle.
     */
    private function execute(
        Model $target,
        CreateCommentData|CreateRichCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience,
        ?CommentDocumentData $inputDocument = null,
        ?string $canonicalDocument = null,
    ): Comment {
        $idempotencyKey = $this->lifecycle->idempotencyKey($data->idempotencyKey);
        $targetPrototype = new ($target::class);
        $targetLookupKey = CommentTargetIdentifier::lookupKey($targetPrototype, $target->getKey());
        $commentConnectionName = (new Comment)->getConnectionName();
        $targetConnectionName = $targetPrototype->getConnectionName();
        $sharesConnection = DB::connection($commentConnectionName)->getName()
            === DB::connection($targetConnectionName)->getName();

        if (! $sharesConnection
            && DB::connection($targetConnectionName)->transactionLevel() > 0) {
            throw new InvalidCommentMutationException(
                'Cross-connection comment creation must run after the target transaction commits.',
            );
        }

        $lockIdentities = array_values(array_filter([
            $idempotencyKey === null ? null : "idempotency:{$idempotencyKey}",
            $data->parentId,
        ]));

        return $this->mutationLock->executeMany(
            $lockIdentities,
            function () use (
                $actor,
                $audience,
                $canonicalDocument,
                $commentConnectionName,
                $data,
                $idempotencyKey,
                $inputDocument,
                $sharesConnection,
                $targetLookupKey,
                $targetPrototype,
            ): Comment {
                $idempotencyHash = null;

                try {
                    return DB::connection($commentConnectionName)->transaction(
                        function () use (
                            $actor,
                            $audience,
                            $canonicalDocument,
                            $data,
                            $idempotencyKey,
                            &$idempotencyHash,
                            $inputDocument,
                            $sharesConnection,
                            $targetLookupKey,
                            $targetPrototype,
                        ): Comment {
                            $targetQuery = $targetPrototype->newQuery();

                            if ($sharesConnection) {
                                $targetQuery->lockForUpdate();
                            }

                            $canonicalTarget = $targetQuery->find($targetLookupKey);

                            if (! $canonicalTarget instanceof Model) {
                                throw new InvalidCommentMutationException(
                                    'Comments require a target that still exists.',
                                );
                            }

                            $targetIdentity = CommentTargetIdentifier::canonical($canonicalTarget);
                            $existing = $idempotencyKey === null
                                ? null
                                : Comment::query()
                                    ->withTrashed()
                                    ->where('idempotency_key', $idempotencyKey)
                                    ->lockForUpdate()
                                    ->first();

                            if ($existing instanceof Comment) {
                                $idempotencyHash = $this->idempotencyHash(
                                    $targetIdentity['type'],
                                    $targetIdentity['id'],
                                    $existing->visibility,
                                    $data,
                                    $actor,
                                    $canonicalDocument,
                                );

                                return $this->authorizedReplay(
                                    $existing,
                                    $idempotencyHash,
                                    $canonicalTarget,
                                    $data,
                                    $actor,
                                    $audience,
                                );
                            }

                            $metadata = $data instanceof CreateCommentData
                                ? $this->guard->create($data)
                                : null;
                            $parent = $data->parentId === null
                                ? null
                                : $this->reads->resolve(
                                    $canonicalTarget,
                                    $data->parentId,
                                    $actor,
                                    $audience,
                                    CommentAbility::Reply,
                                    lockForUpdate: true,
                                );
                            $visibility = $parent === null
                                ? $data->visibility
                                : $parent->visibility;

                            if ($parent === null
                                && $audience === CommentAudience::Public
                                && $visibility !== CommentVisibility::Public) {
                                throw new InvalidCommentMutationException(
                                    'Public comment creation is restricted to public visibility.',
                                );
                            }

                            if ($parent?->trashed() === true || $parent?->anonymized_at !== null) {
                                throw new InvalidCommentLifecycleException(
                                    'Replies require an active, non-anonymized parent comment.',
                                );
                            }

                            $this->authorizeCreation(
                                $canonicalTarget,
                                $parent,
                                $data,
                                $actor,
                                $audience,
                                $visibility,
                            );

                            $depth = $parent === null ? 0 : $parent->depth + 1;
                            $maximumDepth = CommentsConfiguration::positiveInteger(
                                'comments.threading.maximum_depth',
                                6,
                            );

                            if ($depth > $maximumDepth) {
                                throw new InvalidCommentMutationException(
                                    "Comment nesting exceeds the configured depth of {$maximumDepth}.",
                                );
                            }

                            $document = null;
                            $body = $data instanceof CreateCommentData ? $data->body : null;

                            if ($data instanceof CreateRichCommentData) {
                                if (! $inputDocument instanceof CommentDocumentData) {
                                    throw new InvalidCommentMutationException(
                                        'Rich comment creation requires a normalized document.',
                                    );
                                }

                                $document = $this->documents->normalizeInput(
                                    $inputDocument,
                                    new CommentMentionContext($canonicalTarget, $actor, $audience),
                                );
                                $body = $this->documents->body($document);
                                $metadata = $this->guard->createRich($data, $body);
                            }

                            if (! is_string($body) || ! is_array($metadata)) {
                                throw new InvalidCommentMutationException(
                                    'Comment content could not be compiled for persistence.',
                                );
                            }

                            if ($idempotencyKey !== null) {
                                $idempotencyHash = $this->idempotencyHash(
                                    $targetIdentity['type'],
                                    $targetIdentity['id'],
                                    $visibility,
                                    $data,
                                    $actor,
                                    $canonicalDocument,
                                );
                            }

                            $configuredStatus = config('comments.moderation.new_status', 'pending');
                            $status = is_string($configuredStatus)
                                ? CommentStatus::tryFrom($configuredStatus)
                                : null;
                            $comment = Comment::query()->create([
                                'commentable_type' => $targetIdentity['type'],
                                'commentable_id' => $targetIdentity['id'],
                                'root_id' => $parent === null ? null : ($parent->root_id ?? $parent->id),
                                'parent_id' => $parent?->id,
                                'depth' => $depth,
                                'actor_type' => $actor->type,
                                'actor_id' => $actor->id,
                                'idempotency_key' => $idempotencyKey,
                                'idempotency_hash' => $idempotencyHash,
                                'body' => $body,
                                'format' => $data instanceof CreateRichCommentData
                                    ? CommentFormat::RichText
                                    : $data->format,
                                'locale' => $data->locale,
                                'status' => $status ?? CommentStatus::Pending,
                                'visibility' => $visibility,
                                'tags' => $data->tags,
                                'metadata' => $metadata,
                                'document' => $document instanceof CommentDocumentData
                                    ? $this->documents->toArray($document)
                                    : null,
                            ]);

                            if (! $comment->exists) {
                                throw new InvalidCommentMutationException(
                                    'The comment could not be created.',
                                );
                            }

                            $this->metadataIndex->synchronize($comment, $metadata);

                            if ($document instanceof CommentDocumentData) {
                                $this->mentions->synchronize($comment, $document);
                            }

                            if ($parent !== null && $parent->increment('reply_count') !== 1) {
                                throw new InvalidCommentMutationException(
                                    'The parent comment reply counter could not be updated.',
                                );
                            }

                            CommentChanged::dispatch(
                                $comment->id,
                                $comment->commentable_type,
                                $comment->commentable_id,
                                CommentChangeOperation::Created,
                                $comment->revision,
                                $actor,
                            );

                            return $document instanceof CommentDocumentData
                                ? $comment->refresh()
                                : $comment;
                        },
                        attempts: CommentsConfiguration::positiveInteger(
                            'comments.transactions.attempts',
                            3,
                        ),
                    );
                } catch (UniqueConstraintViolationException $exception) {
                    if ($idempotencyKey === null || $idempotencyHash === null) {
                        throw $exception;
                    }

                    $existing = Comment::query()
                        ->withTrashed()
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if (! $existing instanceof Comment) {
                        throw $exception;
                    }

                    $canonicalTarget = $targetPrototype->newQuery()->find($targetLookupKey);

                    if (! $canonicalTarget instanceof Model) {
                        throw new InvalidCommentMutationException(
                            'Comments require a target that still exists.',
                        );
                    }

                    return $this->authorizedReplay(
                        $existing,
                        $idempotencyHash,
                        $canonicalTarget,
                        $data,
                        $actor,
                        $audience,
                    );
                }
            },
        );
    }

    /**
     * Authorize one root or reply creation before rich resource resolution.
     */
    private function authorizeCreation(
        Model $target,
        ?Comment $parent,
        CreateCommentData|CreateRichCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience,
        CommentVisibility $visibility,
    ): void {
        if ($parent === null) {
            $this->access->authorize(
                CommentAbility::Create,
                $actor,
                target: $target,
                audience: $audience,
                context: ['visibility' => $visibility->value],
            );

            return;
        }

        $this->access->authorize(
            CommentAbility::View,
            $actor,
            $parent,
            $target,
            $audience,
            asNotFound: $audience !== CommentAudience::Management,
        );
        $this->access->authorize(
            CommentAbility::Reply,
            $actor,
            $parent,
            $target,
            $audience,
            asNotFound: $audience !== CommentAudience::Management,
            context: [
                'requested_visibility' => $data->visibility->value,
                'inherited_visibility' => $visibility->value,
            ],
        );
    }

    /**
     * Build the plain or rich canonical idempotency digest.
     */
    private function idempotencyHash(
        string $targetType,
        string $targetId,
        CommentVisibility $visibility,
        CreateCommentData|CreateRichCommentData $data,
        CommentActorData $actor,
        ?string $canonicalDocument,
    ): string {
        if ($data instanceof CreateCommentData) {
            return $this->idempotency->make(
                $targetType,
                $targetId,
                $data->parentId,
                $visibility,
                $data,
                $actor,
            );
        }

        if (! is_string($canonicalDocument)) {
            throw new InvalidCommentMutationException(
                'Rich comment idempotency requires a canonical document.',
            );
        }

        return $this->idempotency->makeRich(
            $targetType,
            $targetId,
            $data->parentId,
            $visibility,
            $data,
            $actor,
            $canonicalDocument,
        );
    }

    /**
     * Verify an exact keyed request against current target and participation access.
     */
    private function authorizedReplay(
        Comment $comment,
        string $idempotencyHash,
        Model $target,
        CreateCommentData|CreateRichCommentData $data,
        CommentActorData $actor,
        CommentAudience $audience,
    ): Comment {
        if ($comment->idempotency_hash === null
            || ! hash_equals($comment->idempotency_hash, $idempotencyHash)) {
            throw CommentIdempotencyConflictException::forKey();
        }

        if ($comment->parent_id === null) {
            $this->access->authorize(
                CommentAbility::Create,
                $actor,
                target: $target,
                audience: $audience,
                context: ['visibility' => $comment->visibility->value],
                asNotFound: $audience !== CommentAudience::Management,
            );
        } else {
            $parent = $this->reads->resolve(
                $target,
                $comment->parent_id,
                $actor,
                $audience,
                CommentAbility::Reply,
                withTrashed: true,
            );
            $this->access->authorize(
                CommentAbility::View,
                $actor,
                $parent,
                $target,
                $audience,
                asNotFound: $audience !== CommentAudience::Management,
            );
            $this->access->authorize(
                CommentAbility::Reply,
                $actor,
                $parent,
                $target,
                $audience,
                context: [
                    'requested_visibility' => $data->visibility->value,
                    'inherited_visibility' => $comment->visibility->value,
                ],
                asNotFound: $audience !== CommentAudience::Management,
            );
        }

        $comment->wasRecentlyCreated = false;

        return $comment;
    }
}
