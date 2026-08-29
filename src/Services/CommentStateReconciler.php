<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Nvl\Comments\Data\CommentReconciliationResultData;
use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentReaction;
use Nvl\Comments\Models\CommentReport;
use Nvl\Comments\Relations\TextColumnComparison;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Comments\Support\CommentTargetIdentifier;
use Nvl\Media\Enums\MimeType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;

/**
 * Audits denormalized counters and thread lineage with race-safe optional repairs.
 */
final readonly class CommentStateReconciler
{
    /**
     * Create the comment state reconciler.
     */
    public function __construct(
        private CommentMetadataIndexWriter $metadataIndex,
        private CommentMetadataRegistry $metadataRegistry,
        private CommentMutationLock $mutationLock,
    ) {}

    /**
     * Audit or repair comment state in bounded chunks.
     */
    public function reconcile(
        ?Model $target,
        int $chunkSize,
        bool $repair = false,
        ?string $targetLabel = null,
    ): CommentReconciliationResultData {
        if ($chunkSize < 1 || $chunkSize > 10_000) {
            throw new InvalidArgumentException(
                'Comments reconciliation chunk size must be between 1 and 10000.',
            );
        }

        $auditAttachments = $this->attachmentAuditRequired();

        $initial = $this->scan(
            $target,
            $targetLabel,
            $chunkSize,
            $repair,
            $auditAttachments,
        );

        if (! $repair) {
            return $initial;
        }

        $verification = $this->scan(
            $target,
            $targetLabel,
            $chunkSize,
            false,
            $auditAttachments,
        );

        return new CommentReconciliationResultData(
            dryRun: false,
            target: $targetLabel,
            scanned: $initial->scanned,
            drifted: $initial->drifted,
            repaired: $initial->repaired,
            remaining: $verification->drifted,
            replyCountMismatches: $initial->replyCountMismatches,
            reactionCountMismatches: $initial->reactionCountMismatches,
            reportCountMismatches: $initial->reportCountMismatches,
            openReportCountMismatches: $initial->openReportCountMismatches,
            threadMismatches: $initial->threadMismatches,
            unrepairableThreadMismatches: $verification->unrepairableThreadMismatches,
            identityFingerprintMismatches: $verification->identityFingerprintMismatches,
            missingTargetComments: $verification->missingTargetComments,
            invalidAttachmentAssociations: $verification->invalidAttachmentAssociations,
            healthy: $verification->drifted === 0,
            missingMetadataIndexValues: $initial->missingMetadataIndexValues,
            staleMetadataIndexValues: $initial->staleMetadataIndexValues,
        );
    }

    /**
     * Scan the selected comments and optionally apply compare-and-set repairs.
     */
    private function scan(
        ?Model $target,
        ?string $targetLabel,
        int $chunkSize,
        bool $repair,
        bool $auditAttachments,
    ): CommentReconciliationResultData {
        $scanned = 0;
        [$danglingAttachmentOwners, $danglingAttachments] = $auditAttachments
            && $target === null
            ? $this->danglingAttachmentDrift()
            : [0, 0];
        $drifted = $danglingAttachmentOwners;
        $repaired = 0;
        $replyCountMismatches = 0;
        $reactionCountMismatches = 0;
        $reportCountMismatches = 0;
        $openReportCountMismatches = 0;
        $threadMismatches = 0;
        $unrepairableThreadMismatches = 0;
        $identityFingerprintMismatches = 0;
        $missingTargetComments = 0;
        $invalidAttachmentAssociations = $danglingAttachments;
        $missingMetadataIndexValues = 0;
        $staleMetadataIndexValues = 0;
        $maximumDepth = CommentsConfiguration::positiveInteger(
            'comments.threading.maximum_depth',
            6,
        );
        $query = $this->query($target, $maximumDepth);

        $query->chunkById(
            $chunkSize,
            function (Collection $comments) use (
                &$scanned,
                &$drifted,
                &$repaired,
                &$replyCountMismatches,
                &$reactionCountMismatches,
                &$reportCountMismatches,
                &$openReportCountMismatches,
                &$threadMismatches,
                &$unrepairableThreadMismatches,
                &$identityFingerprintMismatches,
                &$missingTargetComments,
                &$invalidAttachmentAssociations,
                &$missingMetadataIndexValues,
                &$staleMetadataIndexValues,
                $auditAttachments,
                $maximumDepth,
                $repair,
            ): void {
                $missingTargetIds = $this->missingCanonicalTargetIds($comments);
                [$fingerprintMismatchIds, $fingerprintMismatchCount] =
                    $this->fingerprintDrift($comments);
                $identityFingerprintMismatches += $fingerprintMismatchCount;
                $invalidAttachments = $auditAttachments
                    ? $this->invalidAttachmentCounts($comments)
                    : [];

                foreach ($comments as $comment) {
                    $scanned++;
                    $updates = [];
                    $missingTarget = isset($missingTargetIds[$comment->id]);
                    $fingerprintMismatch = isset($fingerprintMismatchIds[$comment->id]);
                    $invalidAttachmentCount = $invalidAttachments[$comment->id] ?? 0;
                    [$missingMetadataValues, $staleMetadataValues] =
                        $this->metadataIndexDrift($comment);
                    $metadataIndexMismatch = $missingMetadataValues > 0
                        || $staleMetadataValues > 0;
                    $missingMetadataIndexValues += $missingMetadataValues;
                    $staleMetadataIndexValues += $staleMetadataValues;

                    if ($missingTarget) {
                        $missingTargetComments++;
                    }

                    $invalidAttachmentAssociations += $invalidAttachmentCount;
                    $actualReplyCount = $this->integerAggregate(
                        $comment,
                        'actual_reply_count',
                    );
                    $actualReactionCount = $this->integerAggregate(
                        $comment,
                        'actual_reaction_count',
                    );
                    $actualReportCount = $this->integerAggregate(
                        $comment,
                        'actual_report_count',
                    );
                    $actualOpenReportCount = $this->integerAggregate(
                        $comment,
                        'actual_open_report_count',
                    );

                    if ($comment->reply_count !== $actualReplyCount) {
                        $replyCountMismatches++;
                        $updates['reply_count'] = $actualReplyCount;
                    }

                    if ($comment->reaction_count !== $actualReactionCount) {
                        $reactionCountMismatches++;
                        $updates['reaction_count'] = $actualReactionCount;
                    }

                    if ($comment->report_count !== $actualReportCount) {
                        $reportCountMismatches++;
                        $updates['report_count'] = $actualReportCount;
                    }

                    if ($comment->open_report_count !== $actualOpenReportCount) {
                        $openReportCountMismatches++;
                        $updates['open_report_count'] = $actualOpenReportCount;
                    }

                    $lineage = $this->expectedLineage($comment, $maximumDepth);
                    $threadUnrepairable = $lineage === null;
                    $threadMismatch = $threadUnrepairable
                        || $comment->root_id !== $lineage['root_id']
                        || $comment->depth !== $lineage['depth'];

                    if ($threadMismatch) {
                        $threadMismatches++;

                        if ($threadUnrepairable) {
                            $unrepairableThreadMismatches++;
                        } else {
                            $updates['root_id'] = $lineage['root_id'];
                            $updates['depth'] = $lineage['depth'];
                        }
                    }

                    if ($updates === []
                        && ! $threadUnrepairable
                        && ! $fingerprintMismatch
                        && ! $missingTarget
                        && ! $metadataIndexMismatch
                        && $invalidAttachmentCount === 0) {
                        continue;
                    }

                    $drifted++;

                    if ($repair && ! $fingerprintMismatch) {
                        $repairedState = $updates !== [] && $this->repair($comment, $updates);
                        $repairedMetadata = $metadataIndexMismatch
                            && $this->repairMetadataIndex($comment);

                        if ($repairedState || $repairedMetadata) {
                            $repaired++;
                        }
                    }
                }
            },
            column: 'id',
        );

        return new CommentReconciliationResultData(
            dryRun: ! $repair,
            target: $targetLabel,
            scanned: $scanned,
            drifted: $drifted,
            repaired: $repaired,
            remaining: max(0, $drifted - $repaired),
            replyCountMismatches: $replyCountMismatches,
            reactionCountMismatches: $reactionCountMismatches,
            reportCountMismatches: $reportCountMismatches,
            openReportCountMismatches: $openReportCountMismatches,
            threadMismatches: $threadMismatches,
            unrepairableThreadMismatches: $unrepairableThreadMismatches,
            identityFingerprintMismatches: $identityFingerprintMismatches,
            missingTargetComments: $missingTargetComments,
            invalidAttachmentAssociations: $invalidAttachmentAssociations,
            healthy: $drifted === 0,
            missingMetadataIndexValues: $missingMetadataIndexValues,
            staleMetadataIndexValues: $staleMetadataIndexValues,
        );
    }

    /**
     * Decide whether Media state participates in this reconciliation run.
     */
    private function attachmentAuditRequired(): bool
    {
        $attachmentsEnabled = config('comments.attachments.enabled', true) === true;
        $association = new MediaAssociation;
        $media = new Media;
        $associationSchema = Schema::connection($association->getConnectionName());
        $associationTableExists = $associationSchema->hasTable($association->getTable());

        if (! $associationTableExists) {
            if (! $attachmentsEnabled) {
                return false;
            }

            throw new InvalidArgumentException(
                'Comment attachment reconciliation requires the Media association table when attachments are enabled.',
            );
        }

        $historicalStateQuery = MediaAssociation::query();
        $this->whereExactAssociationValue(
            $historicalStateQuery,
            'associable_type',
            (new Comment)->getMorphClass(),
        );
        $this->whereExactAssociationValue(
            $historicalStateQuery,
            'collection',
            'attachments',
        );
        $hasHistoricalState = $historicalStateQuery->exists();

        if (! $attachmentsEnabled && ! $hasHistoricalState) {
            return false;
        }

        $commentConnection = DB::connection((new Comment)->getConnectionName())->getName();
        $associationConnection = DB::connection($association->getConnectionName())->getName();
        $mediaConnection = DB::connection($media->getConnectionName())->getName();
        $mediaTableExists = Schema::connection($media->getConnectionName())
            ->hasTable($media->getTable());

        if ($commentConnection !== $associationConnection
            || $commentConnection !== $mediaConnection
            || ! $mediaTableExists) {
            throw new InvalidArgumentException(
                'Comment attachment reconciliation requires Comments and complete Media state on one database connection.',
            );
        }

        return true;
    }

    /**
     * Count active Comment associations whose polymorphic owner no longer exists.
     *
     * @return array{int, int}
     */
    private function danglingAttachmentDrift(): array
    {
        $query = MediaAssociation::query()->where('is_active', true);
        $this->whereExactAssociationValue(
            $query,
            'associable_type',
            (new Comment)->getMorphClass(),
        );
        $danglingOwners = [];
        $danglingAssociations = 0;

        $query->chunkById(1_000, function (Collection $associations) use (
            &$danglingOwners,
            &$danglingAssociations,
        ): void {
            $candidateIds = $associations
                ->pluck('associable_id')
                ->filter(static fn (mixed $id): bool => is_string($id) && Str::isUuid($id))
                ->unique()
                ->values()
                ->all();
            $existingIds = $candidateIds === []
                ? []
                : Comment::query()
                    ->withTrashed()
                    ->whereIn((new Comment)->getKeyName(), $candidateIds)
                    ->pluck((new Comment)->getKeyName())
                    ->all();
            $existing = array_fill_keys(array_filter($existingIds, 'is_string'), true);

            foreach ($associations as $association) {
                $ownerId = $association->associable_id;

                if (! isset($existing[$ownerId])) {
                    $danglingAssociations++;
                    $danglingOwners[$ownerId] = true;
                }
            }
        });

        return [count($danglingOwners), $danglingAssociations];
    }

    /**
     * Build the selected aggregate query with a bounded parent chain.
     *
     * @return Builder<Comment>
     */
    private function query(?Model $target, int $maximumDepth): Builder
    {
        $query = Comment::query()
            ->withTrashed()
            ->with('metadataValues')
            ->with($this->parentRelations($maximumDepth))
            ->withCount([
                'replies as actual_reply_count',
                'reactions as actual_reaction_count',
                'reports as actual_report_count',
                'reports as actual_open_report_count' => static fn (Builder $query): Builder => $query
                    ->whereRaw(
                        'status_hash = ?',
                        [CommentIdentity::value('report-status', CommentReportStatus::Open)],
                    ),
            ]);

        if ($target === null) {
            return $query;
        }

        $identity = CommentTargetIdentifier::canonical($target);

        return $query
            ->where(
                'commentable_identity_hash',
                CommentIdentity::pair($identity['type'], $identity['id']),
            );
    }

    /**
     * Return nested parent relations required to derive canonical lineage.
     *
     * @return list<string>
     */
    private function parentRelations(int $maximumDepth): array
    {
        $relations = [];
        $relation = 'parent';

        for ($depth = 0; $depth <= $maximumDepth; $depth++) {
            $relations[] = $relation;
            $relation .= '.parent';
        }

        return $relations;
    }

    /**
     * Find comments whose polymorphic target no longer resolves canonically.
     *
     * @param  Collection<int, Comment>  $comments
     * @return array<string, true>
     */
    private function missingCanonicalTargetIds(Collection $comments): array
    {
        /** @var array<string, list<Comment>> $commentsByType */
        $commentsByType = [];
        $missing = [];

        foreach ($comments as $comment) {
            $commentsByType[$comment->commentable_type][] = $comment;
        }

        foreach ($commentsByType as $morphType => $typedComments) {
            $modelClass = Relation::getMorphedModel($morphType) ?? $morphType;

            if (! is_a($modelClass, Model::class, true)) {
                foreach ($typedComments as $comment) {
                    $missing[$comment->id] = true;
                }

                continue;
            }

            $prototype = new $modelClass;
            $identifiers = [];

            foreach ($typedComments as $comment) {
                try {
                    $identifiers[] = CommentTargetIdentifier::storedKey(
                        $prototype,
                        $comment->commentable_id,
                    );
                } catch (InvalidCommentMutationException) {
                    $missing[$comment->id] = true;
                }
            }

            $identifiers = array_values(array_unique($identifiers, SORT_REGULAR));
            $found = [];

            if ($identifiers !== []) {
                foreach ($prototype->newQuery()->whereKey($identifiers)->get() as $target) {
                    $identity = CommentTargetIdentifier::canonical($target);
                    $found[$identity['id']] = CommentIdentity::pair(
                        $identity['type'],
                        $identity['id'],
                    );
                }
            }

            foreach ($typedComments as $comment) {
                $foundFingerprint = $found[$comment->commentable_id] ?? null;

                if (! is_string($foundFingerprint)
                    || ! hash_equals(
                        $comment->commentable_identity_hash,
                        $foundFingerprint,
                    )) {
                    $missing[$comment->id] = true;
                }
            }
        }

        return $missing;
    }

    /**
     * Find package rows whose derived fingerprints no longer match their raw identity.
     *
     * @param  Collection<int, Comment>  $comments
     * @return array{array<string, true>, int}
     */
    private function fingerprintDrift(Collection $comments): array
    {
        $commentIds = $comments->modelKeys();
        $mismatchedCommentIds = [];
        $mismatches = 0;

        foreach ($comments as $comment) {
            if (! $this->commentFingerprintsReady($comment)) {
                $mismatchedCommentIds[$comment->id] = true;
                $mismatches++;
            }
        }

        if ($commentIds === []) {
            return [$mismatchedCommentIds, $mismatches];
        }

        $reactions = CommentReaction::query()
            ->whereIn('comment_id', $commentIds)
            ->get([
                'comment_id',
                'actor_type',
                'actor_id',
                'actor_identity_hash',
                'type',
                'type_hash',
            ]);

        foreach ($reactions as $reaction) {
            if ($this->identifiedActorFingerprintReady(
                $reaction,
                'actor_type',
                'actor_id',
                'actor_identity_hash',
            ) && $this->valueFingerprintReady(
                $reaction,
                'type',
                'type_hash',
                'reaction-type',
                64,
            )) {
                continue;
            }

            $mismatchedCommentIds[$reaction->comment_id] = true;
            $mismatches++;
        }

        $reports = CommentReport::query()
            ->whereIn('comment_id', $commentIds)
            ->get([
                'comment_id',
                'reporter_type',
                'reporter_id',
                'reporter_identity_hash',
                'status',
                'status_hash',
            ]);

        foreach ($reports as $report) {
            $status = $report->getRawOriginal('status');

            if ($this->identifiedActorFingerprintReady(
                $report,
                'reporter_type',
                'reporter_id',
                'reporter_identity_hash',
            ) && is_string($status)
                && CommentReportStatus::tryFrom($status) !== null
                && $this->hashReady(
                    $report,
                    'status_hash',
                    CommentIdentity::value('report-status', $status),
                )) {
                continue;
            }

            $mismatchedCommentIds[$report->comment_id] = true;
            $mismatches++;
        }

        return [$mismatchedCommentIds, $mismatches];
    }

    private function commentFingerprintsReady(Comment $comment): bool
    {
        $targetType = $comment->getRawOriginal('commentable_type');
        $targetId = $comment->getRawOriginal('commentable_id');
        $actorType = $comment->getRawOriginal('actor_type');
        $actorId = $comment->getRawOriginal('actor_id');
        $status = $comment->getRawOriginal('status');
        $visibility = $comment->getRawOriginal('visibility');

        if (! is_string($targetType)
            || ! $this->textReady($targetType, 100)
            || ! is_string($targetId)
            || ! $this->textReady($targetId, 255)
            || ! $this->hashReady(
                $comment,
                'commentable_identity_hash',
                CommentIdentity::pair($targetType, $targetId),
            )
            || ! $this->commentActorFingerprintReady($comment, $actorType, $actorId)
            || ! is_string($status)
            || CommentStatus::tryFrom($status) === null
            || ! $this->hashReady(
                $comment,
                'status_hash',
                CommentIdentity::value('comment-status', $status),
            )
            || ! is_string($visibility)
            || CommentVisibility::tryFrom($visibility) === null
            || ! $this->hashReady(
                $comment,
                'visibility_hash',
                CommentIdentity::value('comment-visibility', $visibility),
            )) {
            return false;
        }

        return true;
    }

    private function commentActorFingerprintReady(
        Comment $comment,
        mixed $type,
        mixed $identifier,
    ): bool {
        if ($type === null && $identifier === null) {
            return $this->hashReady($comment, 'actor_identity_hash', null);
        }

        if ($type === 'system' && $identifier === null) {
            return $this->hashReady($comment, 'actor_identity_hash', null);
        }

        return is_string($type)
            && $type !== 'system'
            && $this->textReady($type, 100)
            && is_string($identifier)
            && $this->textReady($identifier, 255)
            && $this->hashReady(
                $comment,
                'actor_identity_hash',
                CommentIdentity::pair($type, $identifier),
            );
    }

    private function identifiedActorFingerprintReady(
        Model $model,
        string $typeAttribute,
        string $identifierAttribute,
        string $hashAttribute,
    ): bool {
        $type = $model->getRawOriginal($typeAttribute);
        $identifier = $model->getRawOriginal($identifierAttribute);

        return is_string($type)
            && $type !== 'system'
            && $this->textReady($type, 100)
            && is_string($identifier)
            && $this->textReady($identifier, 255)
            && $this->hashReady(
                $model,
                $hashAttribute,
                CommentIdentity::pair($type, $identifier),
            );
    }

    private function valueFingerprintReady(
        Model $model,
        string $valueAttribute,
        string $hashAttribute,
        string $domain,
        int $maximumLength,
    ): bool {
        $value = $model->getRawOriginal($valueAttribute);

        return is_string($value)
            && $this->textReady($value, $maximumLength)
            && $this->hashReady(
                $model,
                $hashAttribute,
                CommentIdentity::value($domain, $value),
            );
    }

    private function hashReady(
        Model $model,
        string $attribute,
        ?string $expected,
    ): bool {
        $stored = $model->getRawOriginal($attribute);

        if ($expected === null) {
            return $stored === null;
        }

        return is_string($stored) && hash_equals($stored, $expected);
    }

    private function textReady(string $value, int $maximumLength): bool
    {
        return mb_check_encoding($value, 'UTF-8')
            && preg_match('/\S/u', $value) === 1
            && mb_strlen($value) <= $maximumLength;
    }

    /**
     * Count active attachment associations that violate Comments ownership rules.
     *
     * @param  Collection<int, Comment>  $comments
     * @return array<string, int>
     */
    private function invalidAttachmentCounts(Collection $comments): array
    {
        $commentIds = [];
        $anonymizedCommentIds = [];

        foreach ($comments as $comment) {
            $commentIds[] = $comment->id;

            if ($comment->anonymized_at !== null) {
                $anonymizedCommentIds[$comment->id] = true;
            }
        }

        if ($commentIds === []) {
            return [];
        }

        $associationQuery = MediaAssociation::query();
        $this->whereExactAssociationValue(
            $associationQuery,
            'associable_type',
            (new Comment)->getMorphClass(),
        );
        $associationQuery
            ->whereExists($this->exactCommentOwnerQuery($commentIds))
            ->where(function (Builder $query) use ($anonymizedCommentIds): void {
                $query->where('is_active', true);

                if ($anonymizedCommentIds !== []) {
                    $query->orWhere(function (Builder $query) use (
                        $anonymizedCommentIds,
                    ): void {
                        $query->whereExists($this->exactCommentOwnerQuery(
                            array_keys($anonymizedCommentIds),
                        ));
                        $this->whereExactAssociationValue(
                            $query,
                            'collection',
                            'attachments',
                        );
                    });
                }
            })
            ->orderBy('associable_id')
            ->orderBy('order')
            ->orderBy('id');
        $associations = $associationQuery->get();
        $commentIdSet = array_fill_keys($commentIds, true);
        $associations = $associations->filter(
            static fn (MediaAssociation $association): bool => isset(
                $commentIdSet[$association->associable_id],
            ),
        );
        $mediaIds = $associations->pluck('media_id')->unique()->values()->all();

        if ($mediaIds === []) {
            return [];
        }

        $mediaById = Media::query()
            ->withTrashed()
            ->whereIn('id', $mediaIds)
            ->get()
            ->keyBy('id');
        $mediaAssociations = MediaAssociation::query()
            ->whereIn('media_id', $mediaIds)
            ->get([
                'media_id',
                'associable_type',
                'associable_id',
                'collection',
            ]);
        /** @var array<string, list<array{type: string, id: string, collection: string}>> $associationLocations */
        $associationLocations = [];

        foreach ($mediaAssociations as $mediaAssociation) {
            $associationLocations[$mediaAssociation->media_id][] = [
                'type' => $mediaAssociation->associable_type,
                'id' => $mediaAssociation->associable_id,
                'collection' => $mediaAssociation->collection,
            ];
        }

        $allowedMimeTypes = array_map(
            static fn (MimeType $type): string => $type->value,
            [...MimeType::images(), ...MimeType::documents()],
        );
        $allowPublicMedia = config('comments.attachments.allow_public_media', false) === true;
        $maximum = CommentsConfiguration::positiveInteger(
            'comments.attachments.maximum_per_comment',
            5,
        );
        $maximumFileBytes = CommentsConfiguration::positiveInteger(
            'comments.attachments.maximum_file_bytes',
            10 * 1024 * 1024,
        );
        $invalidAssociations = [];
        $associationOwners = [];
        $attachmentIdsByComment = [];

        foreach ($associations as $association) {
            $associationOwners[$association->id] = $association->associable_id;

            if ($association->collection === 'attachments') {
                $attachmentIdsByComment[$association->associable_id][] = $association->id;
            }

            $media = $mediaById->get($association->media_id);
            $shared = false;

            foreach ($associationLocations[$association->media_id] ?? [] as $location) {
                if ($location['type'] !== $association->associable_type
                    || $location['id'] !== $association->associable_id
                    || $location['collection'] !== 'attachments') {
                    $shared = true;

                    break;
                }
            }

            if (isset($anonymizedCommentIds[$association->associable_id])
                || $association->collection !== 'attachments'
                || ! $media instanceof Media
                || $media->trashed()
                || ! $media->isAvailable()
                || $media->size > $maximumFileBytes
                || ($media->is_public && ! $allowPublicMedia)
                || ! in_array($media->mime_type, $allowedMimeTypes, true)
                || (! $media->is_public && $shared)) {
                $invalidAssociations[$association->id] = true;
            }
        }

        foreach ($attachmentIdsByComment as $associationIds) {
            foreach (array_slice($associationIds, $maximum) as $associationId) {
                $invalidAssociations[$associationId] = true;
            }
        }

        $counts = [];

        foreach (array_keys($invalidAssociations) as $associationId) {
            $commentId = $associationOwners[$associationId] ?? null;

            if (is_string($commentId)) {
                $counts[$commentId] = ($counts[$commentId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Build a correlated existence query matching Media string owners to Comment UUIDs exactly.
     *
     * @param  list<string>  $commentIds
     * @return Builder<Comment>
     */
    private function exactCommentOwnerQuery(array $commentIds = []): Builder
    {
        $comment = new Comment;
        $association = new MediaAssociation;
        $connection = $comment->getConnection();
        $driver = $connection->getDriverName();
        $grammar = $connection->getQueryGrammar();
        $commentId = TextColumnComparison::text(
            $grammar->wrap($comment->qualifyColumn($comment->getKeyName())),
            $driver,
        );
        $associationId = TextColumnComparison::text(
            $grammar->wrap($association->qualifyColumn('associable_id')),
            $driver,
        );
        $query = Comment::query()
            ->withTrashed()
            ->selectRaw('1')
            ->whereRaw(new TextColumnComparison(
                $commentId,
                $associationId,
                $driver,
            ));

        if ($commentIds !== []) {
            $query->whereKey($commentIds);
        }

        return $query;
    }

    /**
     * Add a byte-exact Media association value constraint.
     *
     * @param  Builder<MediaAssociation>  $query
     */
    private function whereExactAssociationValue(
        Builder $query,
        string $column,
        string $value,
    ): void {
        $driver = $query->getModel()->getConnection()->getDriverName();
        $wrappedColumn = $query->getQuery()->getGrammar()->wrap(
            $query->qualifyColumn($column),
        );

        $query->whereRaw(
            TextColumnComparison::value($wrappedColumn, $driver),
            [$value, $value],
        );
    }

    /**
     * Read one database aggregate after validating its driver-independent shape.
     */
    private function integerAggregate(Comment $comment, string $attribute): int
    {
        $value = $comment->getAttribute($attribute);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
            return (int) $value;
        }

        throw new LogicException(
            "Comment aggregate [{$attribute}] did not resolve to a non-negative integer.",
        );
    }

    /**
     * Derive canonical root and depth without trusting denormalized lineage.
     *
     * @return array{root_id: string|null, depth: int}|null
     */
    private function expectedLineage(Comment $comment, int $maximumDepth): ?array
    {
        if ($comment->parent_id === null) {
            return ['root_id' => null, 'depth' => 0];
        }

        $seen = [$comment->id => true];
        $current = $comment;
        $depth = 0;

        while ($current->parent_id !== null) {
            if (! $current->relationLoaded('parent')) {
                return null;
            }

            $parent = $current->getRelation('parent');

            if (! $parent instanceof Comment
                || isset($seen[$parent->id])
                || ! hash_equals(
                    $parent->commentable_identity_hash,
                    $comment->commentable_identity_hash,
                )
                || ! hash_equals($parent->commentable_type, $comment->commentable_type)
                || ! hash_equals($parent->commentable_id, $comment->commentable_id)) {
                return null;
            }

            $seen[$parent->id] = true;
            $current = $parent;
            $depth++;

            if ($depth > $maximumDepth) {
                return null;
            }
        }

        return ['root_id' => $current->id, 'depth' => $depth];
    }

    /**
     * Apply a race-safe row repair only while every changed value is still current.
     *
     * @param  array<string, int|string|null>  $updates
     */
    private function repair(Comment $comment, array $updates): bool
    {
        $repaired = $this->mutationLock->execute(
            $comment->id,
            function () use ($comment, $updates): bool {
                $query = Comment::query()->withTrashed()->whereKey($comment->id);

                foreach (array_keys($updates) as $column) {
                    $original = $comment->getRawOriginal($column);

                    if ($original === null) {
                        $query->whereNull($column);
                    } else {
                        $query->where($column, $original);
                    }
                }

                return $query->update($updates) === 1;
            },
        );

        return $repaired === true;
    }

    /**
     * Compare expected hash-only metadata rows with persisted index state.
     *
     * @return array{int, int}
     */
    private function metadataIndexDrift(Comment $comment): array
    {
        try {
            $expectedRows = $this->metadataRegistry->indexRows(
                $comment->anonymized_at === null && ! $comment->trashed()
                    ? $comment->metadata
                    : null,
            );
        } catch (InvalidCommentMutationException) {
            return [0, max(1, $comment->metadataValues->count())];
        }

        $expected = [];

        foreach ($expectedRows as $row) {
            $expected[$row['schema_namespace'].'.'.$row['field_name']] = $row;
        }

        $actual = [];

        foreach ($comment->metadataValues as $row) {
            $actual[$row->schema_namespace.'.'.$row->field_name] = [
                'value_type' => $row->value_type,
                'value_hash' => $row->value_hash,
            ];
        }

        $missing = 0;
        $stale = 0;

        foreach ($expected as $alias => $row) {
            $stored = $actual[$alias] ?? null;

            if ($stored === null) {
                $missing++;

                continue;
            }

            if ($stored['value_type'] !== $row['value_type']
                || ! hash_equals($stored['value_hash'], $row['value_hash'])) {
                $stale++;
            }
        }

        foreach (array_keys($actual) as $alias) {
            if (! isset($expected[$alias])) {
                $stale++;
            }
        }

        return [$missing, $stale];
    }

    /**
     * Rebuild one comment's metadata index under the package mutation lock.
     */
    private function repairMetadataIndex(Comment $comment): bool
    {
        try {
            $this->metadataRegistry->indexRows(
                $comment->anonymized_at === null && ! $comment->trashed()
                    ? $comment->metadata
                    : null,
            );

            return $this->mutationLock->execute(
                $comment->id,
                fn (): bool => DB::connection((new Comment)->getConnectionName())
                    ->transaction(function () use ($comment): bool {
                        $locked = Comment::query()
                            ->withTrashed()
                            ->whereKey($comment->id)
                            ->lockForUpdate()
                            ->first();

                        if (! $locked instanceof Comment) {
                            return false;
                        }

                        $this->metadataIndex->synchronize(
                            $locked,
                            $locked->anonymized_at === null && ! $locked->trashed()
                                ? $locked->metadata
                                : null,
                        );

                        return true;
                    }),
            ) === true;
        } catch (InvalidCommentMutationException) {
            return false;
        }
    }
}
