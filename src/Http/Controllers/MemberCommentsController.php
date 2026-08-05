<?php

declare(strict_types=1);

namespace Nvl\Comments\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Nvl\Comments\Actions\AttachCommentMediaAction;
use Nvl\Comments\Actions\CreateCommentAction;
use Nvl\Comments\Actions\DeleteCommentAction;
use Nvl\Comments\Actions\DetachCommentMediaAction;
use Nvl\Comments\Actions\GetCommentAction;
use Nvl\Comments\Actions\ListCommentAttachmentsAction;
use Nvl\Comments\Actions\ListCommentRevisionsAction;
use Nvl\Comments\Actions\ListCommentsAction;
use Nvl\Comments\Actions\ReportCommentAction;
use Nvl\Comments\Actions\RestoreCommentAction;
use Nvl\Comments\Actions\RestoreCommentRevisionAction;
use Nvl\Comments\Actions\SetCommentReactionAction;
use Nvl\Comments\Actions\UpdateCommentAction;
use Nvl\Comments\Contracts\CommentActorResolver;
use Nvl\Comments\Data\CommentAttachmentData;
use Nvl\Comments\Data\CommentRevisionData;
use Nvl\Comments\Data\MemberCommentData;
use Nvl\Comments\Data\Mutations\AttachCommentMediaData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Data\Mutations\DeleteCommentData;
use Nvl\Comments\Data\Mutations\ReportCommentData;
use Nvl\Comments\Data\Mutations\RestoreCommentData;
use Nvl\Comments\Data\Mutations\RestoreCommentRevisionData;
use Nvl\Comments\Data\Mutations\SetCommentReactionData;
use Nvl\Comments\Data\Mutations\UpdateCommentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Services\CommentAttachmentDataFactory;
use Nvl\Comments\Services\CommentAttachmentUrlFactory;
use Nvl\Comments\Services\CommentProjectionFactory;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Services\CommentTargetRegistry;
use Nvl\Filterable\Http\QueryFilterSetFactory;
use Nvl\Media\Models\MediaAssociation;

/**
 * Authenticated viewer-aware comment endpoints.
 */
final class MemberCommentsController extends Controller
{
    /**
     * List public comments plus the member's policy-scoped comments.
     */
    public function index(
        Request $request,
        string $target,
        string $targetId,
        CommentTargetRegistry $targets,
        CommentActorResolver $actors,
        QueryFilterSetFactory $filterFactory,
        ListCommentsAction $action,
        CommentProjectionFactory $projections,
    ): JsonResponse {
        $model = $targets->resolve($target, $targetId);
        $actor = $actors->fromRequest($request);
        $comments = $action->execute(
            $model,
            $actor,
            $filterFactory->fromHttpQuery(
                $this->query($request),
                Comment::filterSchema(),
            ),
            $request->has('per_page') ? $request->integer('per_page') : null,
            CommentAudience::Member,
        );

        return $this->respond([
            'data' => array_map(
                static fn (MemberCommentData $comment): array => $comment->toArray(),
                $projections->memberCollection(
                    new Collection($comments->items()),
                    $model,
                    $actor,
                ),
            ),
            'meta' => $this->pagination($comments),
        ]);
    }

    /**
     * Create a member comment with optional header-based idempotency.
     */
    public function store(
        Request $request,
        string $target,
        string $targetId,
        CommentTargetRegistry $targets,
        CommentActorResolver $actors,
        CreateCommentAction $action,
        CommentProjectionFactory $projections,
    ): JsonResponse {
        $model = $targets->resolve($target, $targetId);
        $actor = $actors->fromRequest($request);
        $comment = $action->execute(
            $model,
            CreateCommentData::validateAndCreate($this->creationPayload($request)),
            $actor,
            CommentAudience::Member,
        );

        return $this->respond([
            'data' => $projections->memberComment($comment, $model, $actor)->toArray(),
        ], $comment->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Show one member-scoped comment.
     */
    public function show(
        Request $request,
        string $comment,
        CommentActorResolver $actors,
        GetCommentAction $action,
        CommentProjectionFactory $projections,
        CommentTargetLocator $targets,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $comment = $action->execute($comment, $actor, CommentAudience::Member);

        return $this->respond([
            'data' => $projections
                ->memberComment($comment, $targets->locate($comment), $actor)
                ->toArray(),
        ]);
    }

    /**
     * Update one member-owned comment.
     */
    public function update(
        Request $request,
        string $comment,
        CommentActorResolver $actors,
        UpdateCommentAction $action,
        CommentProjectionFactory $projections,
        CommentTargetLocator $targets,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $comment = $action->execute(
            $comment,
            UpdateCommentData::validateAndCreate($request->all()),
            $actor,
            CommentAudience::Member,
        );

        return $this->respond([
            'data' => $projections
                ->memberComment($comment, $targets->locate($comment), $actor)
                ->toArray(),
        ]);
    }

    /**
     * Delete one member-owned comment and return its tombstone.
     */
    public function destroy(
        Request $request,
        string $comment,
        CommentActorResolver $actors,
        DeleteCommentAction $action,
        CommentProjectionFactory $projections,
        CommentTargetLocator $targets,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $action->execute(
            $comment,
            DeleteCommentData::validateAndCreate($request->all()),
            $actor,
            CommentAudience::Member,
        );
        $comment = Comment::query()->withTrashed()->findOrFail($comment);

        return $this->respond([
            'data' => $projections
                ->memberComment($comment, $targets->locate($comment), $actor)
                ->toArray(),
        ]);
    }

    /**
     * Restore one deleted member comment to the configured review state.
     */
    public function restore(
        Request $request,
        string $comment,
        CommentActorResolver $actors,
        RestoreCommentAction $action,
        CommentProjectionFactory $projections,
        CommentTargetLocator $targets,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $comment = $action->execute(
            $comment,
            RestoreCommentData::validateAndCreate($request->all()),
            $actor,
            CommentAudience::Member,
        );

        return $this->respond([
            'data' => $projections
                ->memberComment($comment, $targets->locate($comment), $actor)
                ->toArray(),
        ]);
    }

    /**
     * Set one member reaction and return deterministic viewer-aware aggregates.
     */
    public function react(
        Request $request,
        string $comment,
        CommentActorResolver $actors,
        SetCommentReactionAction $action,
        CommentProjectionFactory $projections,
        CommentTargetLocator $targets,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $data = SetCommentReactionData::validateAndCreate($request->all());
        $action->execute(
            $comment,
            $data->type,
            $data->active,
            $actor,
            CommentAudience::Member,
        );
        $comment = Comment::query()->findOrFail($comment);

        return $this->respond([
            'data' => $projections
                ->memberComment($comment, $targets->locate($comment), $actor)
                ->toArray(),
        ]);
    }

    /**
     * Submit or reopen a report without returning management facts.
     */
    public function report(
        Request $request,
        string $comment,
        CommentActorResolver $actors,
        ReportCommentAction $action,
    ): JsonResponse {
        $action->execute(
            $comment,
            ReportCommentData::validateAndCreate($request->all()),
            $actors->fromRequest($request),
            CommentAudience::Member,
        );

        return $this->respond(['data' => ['reported' => true]], 202);
    }

    /**
     * List authorized attachment associations and signed asset URLs.
     */
    public function attachments(
        Request $request,
        string $comment,
        CommentActorResolver $actors,
        ListCommentAttachmentsAction $action,
    ): JsonResponse {
        return $this->respond([
            'data' => $action->execute(
                $comment,
                $actors->fromRequest($request),
                CommentAudience::Member,
            )->map(
                static fn (CommentAttachmentData $attachment): array => $attachment->toArray(),
            )->all(),
        ]);
    }

    /**
     * Attach one Media record and return its safe association projection.
     */
    public function attach(
        Request $request,
        string $comment,
        CommentActorResolver $actors,
        AttachCommentMediaAction $action,
        CommentAttachmentDataFactory $attachments,
        CommentAttachmentUrlFactory $attachmentUrls,
        CommentTargetLocator $targets,
    ): JsonResponse {
        $attachmentUrls->assertAvailable();
        $actor = $actors->fromRequest($request);
        $data = AttachCommentMediaData::validateAndCreate($request->all());
        $association = $action->execute(
            $comment,
            $data->mediaId,
            $actor,
            CommentAudience::Member,
        );
        $comment = Comment::query()->findOrFail($comment);
        $association = MediaAssociation::query()
            ->with(['media.imageVariations', 'media.translations'])
            ->findOrFail($association->id);
        $attachment = $attachments->fromAssociation(
            $association,
            $comment,
            $targets->locate($comment),
            $actor,
            CommentAudience::Member,
        );

        if ($attachment === null) {
            throw (new ModelNotFoundException)->setModel(
                MediaAssociation::class,
                [$association->id],
            );
        }

        return $this->respond(['data' => $attachment->toArray()], 201);
    }

    /**
     * Idempotently detach one selected association.
     */
    public function detach(
        Request $request,
        string $comment,
        string $association,
        CommentActorResolver $actors,
        DetachCommentMediaAction $action,
    ): JsonResponse {
        $action->execute(
            $comment,
            $association,
            $actors->fromRequest($request),
            CommentAudience::Member,
        );

        return $this->respond(null, 204);
    }

    /**
     * Return a separately paginated revision history.
     */
    public function revisions(
        Request $request,
        string $comment,
        CommentActorResolver $actors,
        ListCommentRevisionsAction $action,
    ): JsonResponse {
        $revisions = $action->execute(
            $comment,
            $actors->fromRequest($request),
            $request->has('per_page') ? $request->integer('per_page') : null,
            CommentAudience::Member,
        );

        return $this->respond([
            'data' => array_map(
                static fn (CommentRevision $revision): array => CommentRevisionData::fromModel(
                    $revision,
                )->toArray(),
                $revisions->items(),
            ),
            'meta' => $this->pagination($revisions),
        ]);
    }

    /**
     * Restore a selected historical snapshot as a new current revision.
     */
    public function restoreRevision(
        Request $request,
        string $comment,
        string $revision,
        CommentActorResolver $actors,
        RestoreCommentRevisionAction $action,
        CommentProjectionFactory $projections,
        CommentTargetLocator $targets,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $comment = $action->execute(
            $comment,
            $revision,
            RestoreCommentRevisionData::validateAndCreate($request->all()),
            $actor,
            CommentAudience::Member,
        );

        return $this->respond([
            'data' => $projections
                ->memberComment($comment, $targets->locate($comment), $actor)
                ->toArray(),
        ]);
    }

    /**
     * Copy an optional idempotency header into the validated creation payload.
     *
     * @return array<string, mixed>
     */
    private function creationPayload(Request $request): array
    {
        $payload = [];

        foreach ($request->all() as $key => $value) {
            if (is_string($key)) {
                $payload[$key] = $value;
            }
        }

        $header = $request->header('Idempotency-Key');

        if (is_string($header) && $header !== '') {
            if (isset($payload['idempotencyKey'])
                && $payload['idempotencyKey'] !== $header) {
                throw ValidationException::withMessages([
                    'idempotencyKey' => 'The body and Idempotency-Key header must match.',
                ]);
            }

            $payload['idempotencyKey'] = $header;
        }

        return $payload;
    }

    /**
     * Return string-keyed HTTP query input for the allowlisted parser.
     *
     * @return array<string, mixed>
     */
    private function query(Request $request): array
    {
        $query = [];

        foreach ($request->query() as $key => $value) {
            if (is_string($key)) {
                $query[$key] = $value;
            }
        }

        return $query;
    }

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param  LengthAwarePaginator<TKey, TValue>  $paginator
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * Return one private/no-store member response.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function respond(?array $payload, int $status = 200): JsonResponse
    {
        return response()
            ->json($payload, $status)
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
