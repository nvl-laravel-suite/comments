<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentChangeOperation;
use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Comments\Events\CommentChanged;
use Nvl\Comments\Events\CommentMentionsChanged;
use Nvl\Comments\Exceptions\CommentIdempotencyConflictException;
use Nvl\Comments\Exceptions\InvalidCommentLifecycleException;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Ownership\CommentsOwnership;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Comments\Support\CommentTargetIdentifier;
use Nvl\Comments\ValueObjects\CommentMentionContext;
use Nvl\Support\Enums\PublicActor;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/**
 * Owns atomic native comment creation and delegates lifecycle mutations to the
 * existing domain writers.
 */
final readonly class CommentWriter
{
    /**
     * Create the shared comment creation writer.
     */
    public function __construct(
        private CommentAccessService $access,
        private CommentContentGuard $guard,
        private CommentDeletionWriter $deletions,
        private CommentUpdateWriter $updates,
        private CommentRichUpdateWriter $richUpdates,
        private CommentRestorationWriter $restorations,
        private CommentRevisionRestorer $revisions,
        private CommentAnonymizer $anonymizer,
        private CommentDocumentNormalizer $documents,
        private CommentIdempotencyDigest $idempotency,
        private CommentLifecycleGuard $lifecycle,
        private CommentMentionWriter $mentions,
        private CommentMetadataIndexWriter $metadataIndex,
        private CommentMutationLock $mutationLock,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
        private OwnershipBoundary $boundary,
        private DomainEventDispatcher $domainEvents,
    ) {}

    /**
     * Create one plain or Markdown comment through the shared lifecycle.
     */
    public function create(
        Model $target,
        array $data,
        Authenticatable|SystemActor|PublicActor $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment {
        $this->targets->assertCanonical($target);
        $data = $this->normalizeCreate($data);
        if ($data['format'] === CommentFormat::RichText) {
            throw new InvalidCommentMutationException(
                'Rich comments require the rich creation operation.',
            );
        }

        return $this->execute($target, $data, CommentActor::from($actor), $audience);
    }

    /**
     * Create one rich comment through the shared lifecycle.
     */
    public function createRich(
        Model $target,
        array $data,
        Authenticatable|SystemActor|PublicActor $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment {
        $this->targets->assertCanonical($target);
        $data = $this->normalizeCreate($data, rich: true);
        $document = $this->documents->normalizeUnresolved($data['document']);

        return $this->execute(
            $target,
            $data,
            CommentActor::from($actor),
            $audience,
            $document,
            $this->documents->canonicalJson($document),
        );
    }

    public function delete(
        Comment|string $comment,
        int $expectedRevision,
        Authenticatable|SystemActor|PublicActor $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): bool {
        return $this->deletions->delete(
            $comment,
            $expectedRevision,
            CommentActor::from($actor),
            $audience,
        );
    }

    /** @param array<string, mixed> $attributes */
    public function update(
        Comment|string $comment,
        array $attributes,
        Authenticatable|SystemActor|PublicActor $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment {
        return $this->updates->update($comment, $attributes, CommentActor::from($actor), $audience);
    }

    /** @param array<string, mixed> $attributes */
    public function updateRich(
        Comment|string $comment,
        array $attributes,
        Authenticatable|SystemActor|PublicActor $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment {
        return $this->richUpdates->update($comment, $attributes, CommentActor::from($actor), $audience);
    }

    public function restore(
        Comment|string $comment,
        int $expectedRevision,
        Authenticatable|SystemActor|PublicActor $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment {
        return $this->restorations->restore(
            $comment,
            $expectedRevision,
            CommentActor::from($actor),
            $audience,
        );
    }

    public function restoreRevision(
        Comment|string $comment,
        CommentRevision|string $revision,
        int $expectedRevision,
        Authenticatable|SystemActor|PublicActor $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): Comment {
        return $this->revisions->restore(
            $comment,
            $revision,
            $expectedRevision,
            CommentActor::from($actor),
            $audience,
        );
    }

    /** @param array{expectedRevision: int, reason: string} $attributes */
    public function anonymize(
        Comment|string $comment,
        array $attributes,
        Authenticatable|SystemActor $actor,
        CommentAudience $audience = CommentAudience::Management,
    ): Comment {
        return $this->anonymizer->anonymize(
            $comment,
            $attributes,
            CommentActor::from($actor),
            $audience,
        );
    }

    /**
     * Execute the common target, hierarchy, access, idempotency, and persistence lifecycle.
     */
    private function execute(
        Model $target,
        array $data,
        CommentActor $actor,
        CommentAudience $audience,
        ?array $inputDocument = null,
        ?string $canonicalDocument = null,
    ): Comment {
        $idempotencyKey = $this->lifecycle->idempotencyKey($data['idempotencyKey']);
        $targetPrototype = new ($target::class);
        $targetLookupKey = CommentTargetIdentifier::lookupKey($targetPrototype, $target->getKey());
        $commentConnectionName = (CommentModelRegistry::newInstance())->getConnectionName();
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
            $data['parentId'],
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

                            if (CommentsOwnership::active()) {
                                $targetQuery = $this->boundary->query(
                                    $targetQuery,
                                    CommentsOwnership::resource($targetPrototype),
                                );
                            }

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
                                : $this->boundary->query(CommentModelRegistry::query(), 'comments.comments')
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

                            $metadata = ! isset($data['document'])
                                ? $this->guard->create($data)
                                : null;
                            $parent = $data['parentId'] === null
                                ? null
                                : $this->reads->resolve(
                                    $canonicalTarget,
                                    $data['parentId'],
                                    $actor,
                                    $audience,
                                    CommentAbility::Reply,
                                    lockForUpdate: true,
                                );
                            $visibility = $parent === null
                                ? $data['visibility']
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
                                'nvl-comments.threading.maximum_depth',
                                6,
                            );

                            if ($depth > $maximumDepth) {
                                throw new InvalidCommentMutationException(
                                    "Comment nesting exceeds the configured depth of {$maximumDepth}.",
                                );
                            }

                            $document = null;
                            $body = ! isset($data['document']) ? $data['body'] : null;

                            if (isset($data['document'])) {
                                if (! is_array($inputDocument)) {
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

                            $configuredStatus = config('nvl-comments.moderation.new_status', 'pending');
                            $status = is_string($configuredStatus)
                                ? CommentStatus::tryFrom($configuredStatus)
                                : null;
                            $comment = CommentModelRegistry::create([
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
                                'format' => isset($data['document'])
                                    ? CommentFormat::RichText
                                    : $data['format'],
                                'locale' => $data['locale'],
                                'status' => $status ?? CommentStatus::Pending,
                                'visibility' => $visibility,
                                'tags' => $data['tags'],
                                'metadata' => $metadata,
                                'document' => is_array($document)
                                    ? $this->documents->toArray($document)
                                    : null,
                            ]);

                            if (! $comment->exists) {
                                throw new InvalidCommentMutationException(
                                    'The comment could not be created.',
                                );
                            }

                            $this->metadataIndex->synchronize($comment, $metadata);

                            if (is_array($document)) {
                                $this->mentions->synchronize($comment, $document);
                                $changes = $this->mentions->changes(null, $document);

                                if ($changes['added'] !== [] || $changes['removed'] !== []) {
                                    $this->domainEvents->dispatch(new CommentMentionsChanged(
                                        $comment->id,
                                        $comment->commentable_type,
                                        $comment->commentable_id,
                                        $comment->revision,
                                        $changes['added'],
                                        $changes['removed'],
                                    ), $comment->getConnection());
                                }
                            }

                            if ($parent !== null && $parent->increment('reply_count') !== 1) {
                                throw new InvalidCommentMutationException(
                                    'The parent comment reply counter could not be updated.',
                                );
                            }

                            $this->domainEvents->dispatch(new CommentChanged(
                                $comment->id,
                                $comment->commentable_type,
                                $comment->commentable_id,
                                CommentChangeOperation::Created,
                                $comment->revision,
                                $actor,
                            ), $comment->getConnection());

                            return is_array($document)
                                ? $comment->refresh()
                                : $comment;
                        },
                        attempts: CommentsConfiguration::positiveInteger(
                            'nvl-comments.transactions.attempts',
                            3,
                        ),
                    );
                } catch (UniqueConstraintViolationException $exception) {
                    if ($idempotencyKey === null || $idempotencyHash === null) {
                        throw $exception;
                    }

                    $existing = $this->boundary->query(CommentModelRegistry::query(), 'comments.comments')
                        ->withTrashed()
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if (! $existing instanceof Comment) {
                        throw $exception;
                    }

                    $targetQuery = $targetPrototype->newQuery();
                    if (CommentsOwnership::active()) {
                        $targetQuery = $this->boundary->query(
                            $targetQuery,
                            CommentsOwnership::resource($targetPrototype),
                        );
                    }
                    $canonicalTarget = $targetQuery->find($targetLookupKey);

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
        array $data,
        CommentActor $actor,
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
                'requested_visibility' => $data['visibility']->value,
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
        array $data,
        CommentActor $actor,
        ?string $canonicalDocument,
    ): string {
        if (! isset($data['document'])) {
            return $this->idempotency->make(
                $targetType,
                $targetId,
                $data['parentId'],
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
            $data['parentId'],
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
        array $data,
        CommentActor $actor,
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
                    'requested_visibility' => $data['visibility']->value,
                    'inherited_visibility' => $comment->visibility->value,
                ],
                asNotFound: $audience !== CommentAudience::Management,
            );
        }

        $comment->wasRecentlyCreated = false;

        return $comment;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeCreate(array $attributes, bool $rich = false): array
    {
        $allowed = ['visibility', 'locale', 'parentId', 'tags', 'metadata', 'idempotencyKey'];
        $allowed = $rich ? [...$allowed, 'document'] : [...$allowed, 'body', 'format'];
        if (array_diff(array_keys($attributes), $allowed) !== []) {
            throw new InvalidCommentMutationException('Comment creation contains unsupported attributes.');
        }

        $visibility = $attributes['visibility'] ?? CommentVisibility::Public;
        if (is_string($visibility)) {
            $visibility = CommentVisibility::tryFrom($visibility);
        }

        $format = $rich ? CommentFormat::RichText : ($attributes['format'] ?? CommentFormat::Plain);
        if (is_string($format)) {
            $format = CommentFormat::tryFrom($format);
        }

        $locale = $attributes['locale'] ?? null;
        $parentId = $attributes['parentId'] ?? null;
        $idempotencyKey = $attributes['idempotencyKey'] ?? null;
        $tags = $attributes['tags'] ?? [];
        $metadata = $attributes['metadata'] ?? [];
        if (! $visibility instanceof CommentVisibility
            || ! $format instanceof CommentFormat
            || ($locale !== null && ! is_string($locale))
            || ($parentId !== null && ! is_string($parentId))
            || ($idempotencyKey !== null && ! is_string($idempotencyKey))
            || ! is_array($tags) || ! array_is_list($tags)
            || ! is_array($metadata) || array_is_list($metadata) && $metadata !== []) {
            throw new InvalidCommentMutationException('Comment creation contains invalid attributes.');
        }

        $normalized = [
            'format' => $format,
            'visibility' => $visibility,
            'locale' => $locale,
            'parentId' => $parentId,
            'idempotencyKey' => $idempotencyKey,
            'tags' => $tags,
            'metadata' => $metadata,
        ];

        if ($rich) {
            $document = $attributes['document'] ?? null;
            if (! is_array($document)
                || ! is_int($document['version'] ?? null)
                || ! is_array($document['blocks'] ?? null)) {
                throw new InvalidCommentMutationException('Rich comment creation requires a document.');
            }

            $normalized['document'] = $document;
        } else {
            if (! is_string($attributes['body'] ?? null)) {
                throw new InvalidCommentMutationException('Comment creation requires a body.');
            }
            $normalized['body'] = $attributes['body'];
        }

        return $normalized;
    }
}
