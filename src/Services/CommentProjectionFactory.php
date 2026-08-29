<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Nvl\Comments\Contracts\CommentAuthorPresenter;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Data\CommentAbilitiesData;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentManagementData;
use Nvl\Comments\Data\CommentMetadataProjectionData;
use Nvl\Comments\Data\CommentReactionSummaryData;
use Nvl\Comments\Data\CommentRevisionData;
use Nvl\Comments\Data\MemberCommentData;
use Nvl\Comments\Data\MemberCommentReactionSummaryData;
use Nvl\Comments\Data\PublicCommentData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentReaction;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentTargetIdentifier;
use Nvl\Media\Models\MediaAssociation;
use Spatie\LaravelData\Optional;

/**
 * Builds batched public and member comment projections without per-row queries.
 */
final readonly class CommentProjectionFactory
{
    public function __construct(
        private CommentAccessService $access,
        private CommentAttachmentDataFactory $attachments,
        private CommentAuthorPresenter $authors,
        private CommentQueryScope $queryScope,
        private CommentMentionProjectionFactory $mentions,
        private CommentMetadataRegistry $metadata,
    ) {}

    /**
     * Build viewer-independent public projections for a pre-authorized batch.
     *
     * @param  Collection<int, Comment>  $comments
     * @return list<PublicCommentData>
     */
    public function publicCollection(Collection $comments, Model $target): array
    {
        if ($comments->isEmpty()) {
            return [];
        }

        $this->assertTargetOwnership($comments, $target);
        $actor = CommentActorData::anonymous();
        $authors = $this->authors->present($comments, CommentAudience::Public);
        $replyCounts = $this->visibleReplyCounts(
            $comments,
            $target,
            $actor,
            CommentAudience::Public,
        );
        $reactions = $this->publicReactionSummaries($comments);
        $attachmentCounts = $this->visibleAttachmentCounts(
            $comments,
            $target,
            $actor,
            CommentAudience::Public,
        );
        $rich = $this->mentions->project(
            $comments,
            $target,
            $actor,
            CommentAudience::Public,
        );

        return array_values($comments->map(
            fn (Comment $comment): PublicCommentData => PublicCommentData::fromModel(
                $comment,
                $authors[$comment->id] ?? null,
                $replyCounts[$comment->id] ?? 0,
                $reactions[$comment->id] ?? [],
                $attachmentCounts[$comment->id] ?? 0,
                $this->projectedMetadata($comment, CommentAudience::Public),
                $rich[$comment->id]['document'],
                $rich[$comment->id]['mentions'],
            ),
        )->all());
    }

    /**
     * Build a viewer-independent public projection for one pre-authorized comment.
     */
    public function publicComment(Comment $comment, Model $target): PublicCommentData
    {
        return $this->publicCollection(new Collection([$comment]), $target)[0];
    }

    /**
     * Build viewer-aware member projections for a pre-authorized batch.
     *
     * @param  Collection<int, Comment>  $comments
     * @return list<MemberCommentData>
     */
    public function memberCollection(
        Collection $comments,
        Model $target,
        CommentActorData $actor,
    ): array {
        if ($comments->isEmpty()) {
            return [];
        }

        $this->assertTargetOwnership($comments, $target);
        $authors = $this->authors->present($comments, CommentAudience::Member);
        $replyCounts = $this->visibleReplyCounts(
            $comments,
            $target,
            $actor,
            CommentAudience::Member,
        );
        $reactions = $this->memberReactionSummaries(
            $comments,
            $actor,
        );
        $attachmentCounts = $this->visibleAttachmentCounts(
            $comments,
            $target,
            $actor,
            CommentAudience::Member,
        );
        $rich = $this->mentions->project(
            $comments,
            $target,
            $actor,
            CommentAudience::Member,
        );

        return array_values($comments->map(
            fn (Comment $comment): MemberCommentData => MemberCommentData::fromModel(
                $comment,
                $authors[$comment->id] ?? null,
                $replyCounts[$comment->id] ?? 0,
                $reactions[$comment->id] ?? [],
                $this->isAuthor($comment, $actor),
                $this->abilities($comment, $target, $actor),
                $attachmentCounts[$comment->id] ?? 0,
                $this->projectedMetadata($comment, CommentAudience::Member),
                $rich[$comment->id]['document'],
                $rich[$comment->id]['mentions'],
            ),
        )->all());
    }

    /**
     * Build a viewer-aware member projection for one pre-authorized comment.
     */
    public function memberComment(
        Comment $comment,
        Model $target,
        CommentActorData $actor,
    ): MemberCommentData {
        return $this->memberCollection(new Collection([$comment]), $target, $actor)[0];
    }

    /**
     * Build privileged projections with target-scoped visible reply counts.
     *
     * @param  Collection<int, Comment>  $comments
     * @return array<string, CommentManagementData>
     */
    public function managementCollection(
        Collection $comments,
        Model $target,
        CommentActorData $actor,
    ): array {
        if ($comments->isEmpty()) {
            return [];
        }

        $this->assertTargetOwnership($comments, $target);
        $replyCounts = $this->visibleReplyCounts(
            $comments,
            $target,
            $actor,
            CommentAudience::Management,
            CommentAbility::Moderate,
        );
        $rich = $this->mentions->project(
            $comments,
            $target,
            $actor,
            CommentAudience::Management,
        );
        $projections = [];

        foreach ($comments as $comment) {
            $projections[$comment->id] = CommentManagementData::fromModel(
                $comment,
                $replyCounts[$comment->id] ?? 0,
                $this->canViewManagementIdentity($comment, $target, $actor),
                $this->projectedMetadata($comment, CommentAudience::Management),
                $rich[$comment->id]['document'],
                $rich[$comment->id]['mentions'],
            );
        }

        return $projections;
    }

    /**
     * Build one privileged projection through the batched path.
     */
    public function managementComment(
        Comment $comment,
        Model $target,
        CommentActorData $actor,
    ): CommentManagementData {
        return $this->managementCollection(
            new Collection([$comment]),
            $target,
            $actor,
        )[$comment->id];
    }

    /**
     * Build one audience-safe historical revision projection.
     */
    public function revision(
        CommentRevision $revision,
        CommentAudience $audience,
    ): CommentRevisionData {
        $metadata = $this->metadata->project($revision->metadata, $audience);

        return CommentRevisionData::fromModel(
            $revision,
            $metadata === [] ? Optional::create() : $metadata,
        );
    }

    /**
     * Ask the policy separately before exposing stored management identities.
     */
    public function canViewManagementIdentity(
        Comment $comment,
        Model $target,
        CommentActorData $actor,
    ): bool {
        return $this->access->allows(
            CommentAbility::ViewIdentity,
            $actor,
            $comment,
            $target,
            CommentAudience::Management,
        );
    }

    /**
     * Return visible registered metadata or omit the projection for compatibility.
     *
     * @return list<CommentMetadataProjectionData>|Optional
     */
    private function projectedMetadata(
        Comment $comment,
        CommentAudience $audience,
    ): array|Optional {
        if ($this->isTombstone($comment)) {
            return Optional::create();
        }

        $metadata = $this->metadata->project($comment->metadata, $audience);

        return $metadata === [] ? Optional::create() : $metadata;
    }

    /**
     * Ensure an already-loaded batch belongs to its canonical target.
     *
     * @param  Collection<int, Comment>  $comments
     */
    private function assertTargetOwnership(Collection $comments, Model $target): void
    {
        $identity = CommentTargetIdentifier::canonical($target);
        $fingerprint = CommentIdentity::pair($identity['type'], $identity['id']);

        foreach ($comments as $comment) {
            if (! hash_equals($comment->commentable_type, $identity['type'])
                || ! hash_equals($comment->commentable_id, $identity['id'])
                || ! hash_equals($comment->commentable_identity_hash, $fingerprint)) {
                throw (new ModelNotFoundException)->setModel(Comment::class, [$comment->id]);
            }
        }
    }

    private function abilities(
        Comment $comment,
        Model $target,
        CommentActorData $actor,
    ): CommentAbilitiesData {
        $tombstone = $comment->trashed()
            || $comment->getAttribute('anonymized_at') !== null;

        if ($tombstone) {
            return CommentAbilitiesData::none();
        }

        return new CommentAbilitiesData(
            reply: $this->allows(CommentAbility::Reply, $comment, $target, $actor),
            update: $this->allows(CommentAbility::Update, $comment, $target, $actor),
            delete: $this->allows(CommentAbility::Delete, $comment, $target, $actor),
            restore: $this->allows(CommentAbility::Restore, $comment, $target, $actor),
            anonymize: $this->allows(CommentAbility::Anonymize, $comment, $target, $actor),
            react: $this->allows(CommentAbility::React, $comment, $target, $actor),
            report: $this->allows(CommentAbility::Report, $comment, $target, $actor),
            attach: $this->allows(CommentAbility::Attach, $comment, $target, $actor),
            detach: $this->allows(CommentAbility::Detach, $comment, $target, $actor),
            viewHistory: $this->allows(CommentAbility::ViewHistory, $comment, $target, $actor),
            restoreRevision: $this->allows(CommentAbility::RestoreRevision, $comment, $target, $actor),
            moderate: $this->allows(CommentAbility::Moderate, $comment, $target, $actor),
        );
    }

    private function allows(
        CommentAbility $ability,
        Comment $comment,
        Model $target,
        CommentActorData $actor,
    ): bool {
        return $this->access->allows(
            $ability,
            $actor,
            $comment,
            $target,
            CommentAudience::Member,
        );
    }

    private function isAuthor(Comment $comment, CommentActorData $actor): bool
    {
        return $actor->type !== null
            && $actor->id !== null
            && $comment->actor_type !== null
            && $comment->actor_id !== null
            && hash_equals($comment->actor_type, $actor->type)
            && hash_equals($comment->actor_id, $actor->id);
    }

    /**
     * Resolve attachment counts after both Comments and Media authorization.
     *
     * @param  Collection<int, Comment>  $comments
     * @return array<string, int>
     */
    private function visibleAttachmentCounts(
        Collection $comments,
        Model $target,
        CommentActorData $actor,
        CommentAudience $audience,
    ): array {
        if (config('comments.attachments.enabled', true) !== true) {
            return [];
        }

        $activeComments = [];

        foreach ($comments as $comment) {
            if (! $this->isTombstone($comment)) {
                $activeComments[$comment->id] = $comment;
            }
        }

        if ($activeComments === []) {
            return [];
        }

        $associations = MediaAssociation::query()
            ->where('associable_type', (new Comment)->getMorphClass())
            ->whereIn('associable_id', array_keys($activeComments))
            ->where('collection', 'attachments')
            ->where('is_active', true)
            ->with(['media.imageVariations', 'media.translations'])
            ->orderBy('order')
            ->orderBy('id')
            ->get();
        $counts = [];

        foreach ($associations as $association) {
            $comment = $activeComments[$association->associable_id] ?? null;

            if (! $comment instanceof Comment) {
                continue;
            }

            $attachment = $this->attachments->fromAssociation(
                $association,
                $comment,
                $target,
                $actor,
                $audience,
            );

            if ($attachment !== null) {
                $counts[$comment->id] = ($counts[$comment->id] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Resolve direct visible reply counts for an already-authorized projection.
     *
     * The trusted query scope still limits the aggregate, but projection must
     * not introduce a second ability denial after a mutation has committed.
     *
     * @param  Collection<int, Comment>  $comments
     * @return array<string, int>
     */
    private function visibleReplyCounts(
        Collection $comments,
        Model $target,
        CommentActorData $actor,
        CommentAudience $audience,
        CommentAbility $ability = CommentAbility::List,
    ): array {
        $commentIds = $this->commentIds($comments);
        $parentColumn = (new Comment)->qualifyColumn('parent_id');
        $identity = CommentTargetIdentifier::canonical($target);

        $query = Comment::query()
            ->withTrashed()
            ->where(
                'commentable_identity_hash',
                CommentIdentity::pair($identity['type'], $identity['id']),
            );
        $this->queryScope->scopeComments(
            $query,
            $actor,
            $target,
            $audience,
            $ability,
        );
        $rows = $query
            ->reorder()
            ->whereIn($parentColumn, $commentIds)
            ->select($parentColumn)
            ->selectRaw('COUNT(*) AS aggregate_count')
            ->groupBy($parentColumn)
            ->get();
        $counts = [];

        foreach ($rows as $row) {
            $parentId = $row->getAttribute('parent_id');

            if (! is_string($parentId)) {
                continue;
            }

            $counts[$parentId] = $this->integerAttribute(
                $row,
                'aggregate_count',
            );
        }

        return $counts;
    }

    /**
     * Resolve viewer-independent configured reaction totals.
     *
     * @param  Collection<int, Comment>  $comments
     * @return array<string, list<CommentReactionSummaryData>>
     */
    private function publicReactionSummaries(Collection $comments): array
    {
        $types = $this->reactionTypes();
        $commentIds = $this->commentIds($comments);
        $counts = $this->reactionCounts($commentIds, $types);
        $summaries = [];

        foreach ($commentIds as $commentId) {
            $summaries[$commentId] = [];

            foreach ($types as $type) {
                $summaries[$commentId][] = new CommentReactionSummaryData(
                    type: $type,
                    count: $counts[$commentId][$type] ?? 0,
                );
            }
        }

        return $summaries;
    }

    /**
     * Resolve configured reaction totals and the member viewer's active state.
     *
     * @param  Collection<int, Comment>  $comments
     * @return array<string, list<MemberCommentReactionSummaryData>>
     */
    private function memberReactionSummaries(
        Collection $comments,
        CommentActorData $actor,
    ): array {
        $types = $this->reactionTypes();
        $commentIds = $this->commentIds($comments);
        $counts = $this->reactionCounts($commentIds, $types);
        $viewerState = $this->viewerReactionState($commentIds, $types, $actor);
        $summaries = [];

        foreach ($commentIds as $commentId) {
            $summaries[$commentId] = [];

            foreach ($types as $type) {
                $summaries[$commentId][] = new MemberCommentReactionSummaryData(
                    type: $type,
                    count: $counts[$commentId][$type] ?? 0,
                    viewerActive: isset($viewerState[$commentId][$type]),
                );
            }
        }

        return $summaries;
    }

    /**
     * @param  list<string>  $commentIds
     * @param  list<string>  $types
     * @return array<string, array<string, int>>
     */
    private function reactionCounts(array $commentIds, array $types): array
    {
        if ($types === []) {
            return [];
        }

        $typesByHash = [];

        foreach ($types as $type) {
            $typesByHash[CommentIdentity::value('reaction-type', $type)] = $type;
        }

        $rows = CommentReaction::query()
            ->whereIn('comment_id', $commentIds)
            ->whereIn('type_hash', array_keys($typesByHash))
            ->select(['comment_id', 'type_hash'])
            ->selectRaw('COUNT(*) AS aggregate_count')
            ->groupBy(['comment_id', 'type_hash'])
            ->get();
        $counts = [];

        foreach ($rows as $row) {
            $commentId = $row->getAttribute('comment_id');
            $typeHash = $row->getAttribute('type_hash');
            $type = is_string($typeHash) ? ($typesByHash[$typeHash] ?? null) : null;

            if (! is_string($commentId) || ! is_string($type)) {
                continue;
            }

            $counts[$commentId][$type] = $this->integerAttribute(
                $row,
                'aggregate_count',
            );
        }

        return $counts;
    }

    /**
     * @param  list<string>  $commentIds
     * @param  list<string>  $types
     * @return array<string, array<string, true>>
     */
    private function viewerReactionState(
        array $commentIds,
        array $types,
        CommentActorData $actor,
    ): array {
        if ($types === [] || $actor->type === null || $actor->id === null) {
            return [];
        }

        $typesByHash = [];

        foreach ($types as $type) {
            $typesByHash[CommentIdentity::value('reaction-type', $type)] = $type;
        }

        $rows = CommentReaction::query()
            ->whereIn('comment_id', $commentIds)
            ->whereIn('type_hash', array_keys($typesByHash))
            ->where(
                'actor_identity_hash',
                CommentIdentity::pair($actor->type, $actor->id),
            )
            ->get(['comment_id', 'type_hash']);
        $state = [];

        foreach ($rows as $row) {
            $commentId = $row->getAttribute('comment_id');
            $typeHash = $row->getAttribute('type_hash');
            $type = is_string($typeHash) ? ($typesByHash[$typeHash] ?? null) : null;

            if (is_string($commentId) && is_string($type)) {
                $state[$commentId][$type] = true;
            }
        }

        return $state;
    }

    /**
     * @param  Collection<int, Comment>  $comments
     * @return list<string>
     */
    private function commentIds(Collection $comments): array
    {
        return array_values($comments->map(
            static fn (Comment $comment): string => $comment->id,
        )->all());
    }

    /**
     * @return list<string>
     */
    private function reactionTypes(): array
    {
        $configured = config('comments.reactions.allowed', []);

        if (! is_array($configured)) {
            return [];
        }

        $types = [];

        foreach ($configured as $type) {
            if (is_string($type) && $type !== '' && ! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    private function integerAttribute(Model $model, string $key): int
    {
        $value = $model->getAttribute($key);

        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value)
            || $value === ''
            || strspn($value, '0123456789') !== strlen($value)) {
            return 0;
        }

        return (int) $value;
    }

    private function isTombstone(Comment $comment): bool
    {
        return $comment->trashed()
            || $comment->getAttribute('anonymized_at') !== null;
    }
}
