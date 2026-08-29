<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentMentionData;
use Nvl\Comments\Data\CommentMentionReferenceData;
use Nvl\Comments\Data\CommentMentionResourceData;
use Nvl\Comments\Data\CommentViewerDocumentBlockData;
use Nvl\Comments\Data\CommentViewerDocumentData;
use Nvl\Comments\Data\Mutations\CommentDocumentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Enums\CommentMentionState;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Comments\ValueObjects\CommentMentionContext;
use Spatie\LaravelData\Optional;

/**
 * Builds batched viewer-safe documents and mention projections from stored snapshots.
 */
final readonly class CommentMentionProjectionFactory
{
    /**
     * Create the mention projection factory.
     */
    public function __construct(
        private CommentDocumentNormalizer $documents,
        private CommentMentionResourceRegistry $resources,
    ) {}

    /**
     * Project one authorized comment batch with one bounded lookup per resource alias.
     *
     * @param  Collection<int, Comment>  $comments
     * @return array<string, array{document: CommentViewerDocumentData|Optional, mentions: list<CommentMentionData>|Optional}>
     */
    public function project(
        Collection $comments,
        Model $target,
        CommentActorData $actor,
        CommentAudience $audience,
    ): array {
        $normalized = [];
        $references = [];
        $idsByAlias = [];

        foreach ($comments as $comment) {
            if ($this->isTombstone($comment)
                || $comment->format !== CommentFormat::RichText
                || ! is_array($comment->document)) {
                continue;
            }

            $document = $this->documents->normalizeStored($comment->document);
            $normalized[$comment->id] = $document;
            $references[$comment->id] = $this->documents->references($document);

            foreach ($references[$comment->id] as $reference) {
                $idsByAlias[$reference->resourceAlias][$reference->resourceId] = true;
            }
        }

        $context = new CommentMentionContext($target, $actor, $audience);
        $live = [];
        $batchSize = min(100, CommentsConfiguration::positiveInteger(
            'comments.mentions.maximum_batch_size',
            100,
        ));

        foreach ($idsByAlias as $alias => $ids) {
            if (! $this->resources->has($alias)
                || ($audience === CommentAudience::Public
                    && ! $this->resources->isViewerIndependent($alias))) {
                continue;
            }

            foreach (array_chunk(array_keys($ids), $batchSize) as $batch) {
                foreach ($this->resources->resolveForProjection($alias, $context, $batch) as $resource) {
                    $live[$alias][$resource->id] = $resource;
                }
            }
        }

        $projected = [];

        foreach ($comments as $comment) {
            $document = $normalized[$comment->id] ?? null;

            if (! $document instanceof CommentDocumentData) {
                $projected[$comment->id] = [
                    'document' => Optional::create(),
                    'mentions' => Optional::create(),
                ];

                continue;
            }

            $mentions = [];

            foreach ($references[$comment->id] as $reference) {
                $resource = isset($live[$reference->resourceAlias][$reference->resourceId])
                    ? $live[$reference->resourceAlias][$reference->resourceId]
                    : null;
                $state = $resource instanceof CommentMentionResourceData
                    ? $resource->state
                    : $this->unresolvedState($reference, $audience);
                $resolved = $state === CommentMentionState::Resolved
                    && $resource instanceof CommentMentionResourceData;
                $mentions[] = new CommentMentionData(
                    tokenId: $reference->tokenId,
                    resourceAlias: $reference->resourceAlias,
                    state: $state,
                    labelSnapshot: $reference->labelSnapshot,
                    resourceId: $resolved ? $resource->id : null,
                    currentLabel: $resolved ? $resource->label : null,
                    fields: $resolved ? $resource->fields : [],
                    url: $resolved ? $resource->url : null,
                );
            }

            $projected[$comment->id] = [
                'document' => $this->viewerDocument($document, $mentions),
                'mentions' => $mentions,
            ];
        }

        return $projected;
    }

    /**
     * Build a viewer-shaped document that omits every stored opaque resource identifier.
     *
     * @param  list<CommentMentionData>  $mentions
     */
    private function viewerDocument(
        CommentDocumentData $document,
        array $mentions,
    ): CommentViewerDocumentData {
        $byToken = [];

        foreach ($mentions as $mention) {
            $byToken[$mention->tokenId] = $mention;
        }

        $blocks = [];

        foreach ($this->documents->toArray($document)['blocks'] as $block) {
            $children = [];

            foreach ($block['children'] as $node) {
                if (($node['type'] ?? null) !== 'mention') {
                    $children[] = $node;

                    continue;
                }

                $mention = $byToken[$node['tokenId']];
                $children[] = [
                    'type' => 'mention',
                    'tokenId' => $mention->tokenId,
                    'resource' => $mention->resourceAlias,
                    'state' => $mention->state->value,
                    'label' => $mention->currentLabel ?? $mention->labelSnapshot,
                ];
            }

            $blocks[] = new CommentViewerDocumentBlockData(
                type: 'paragraph',
                children: $children,
            );
        }

        return new CommentViewerDocumentData(version: 1, blocks: $blocks);
    }

    /**
     * Resolve a safe state when live resolution was intentionally skipped or unavailable.
     */
    private function unresolvedState(
        CommentMentionReferenceData $reference,
        CommentAudience $audience,
    ): CommentMentionState {
        if ($audience === CommentAudience::Public
            && $this->resources->has($reference->resourceAlias)
            && ! $this->resources->isViewerIndependent($reference->resourceAlias)) {
            return CommentMentionState::Restricted;
        }

        return CommentMentionState::Missing;
    }

    /**
     * Determine whether one row must omit its content projection.
     */
    private function isTombstone(Comment $comment): bool
    {
        return $comment->trashed() || $comment->anonymized_at !== null;
    }
}
