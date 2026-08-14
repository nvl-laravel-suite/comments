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
use Nvl\Comments\Actions\ListCommentsAction;
use Nvl\Comments\Actions\ReportCommentAction;
use Nvl\Comments\Actions\SetCommentReactionAction;
use Nvl\Comments\Actions\UpdateCommentAction;
use Nvl\Comments\Contracts\CommentActorResolver;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentAttachmentData;
use Nvl\Comments\Data\Mutations\AttachCommentMediaData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Data\Mutations\DeleteCommentData;
use Nvl\Comments\Data\Mutations\ReportCommentData;
use Nvl\Comments\Data\Mutations\SetCommentReactionData;
use Nvl\Comments\Data\Mutations\UpdateCommentData;
use Nvl\Comments\Data\PublicCommentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentAttachmentDataFactory;
use Nvl\Comments\Services\CommentAttachmentUrlFactory;
use Nvl\Comments\Services\CommentProjectionFactory;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Services\CommentTargetRegistry;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Filterable\Http\QueryFilterSetFactory;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Services\MediaQueryService;

/**
 * Anonymous-capable public discussion endpoints with viewer-independent reads.
 */
final class PublicCommentsController extends Controller
{
    private const int SIGNED_URL_CACHE_SAFETY_SECONDS = 30;

    public function __construct(
        private readonly CommentReadService $reads,
        private readonly MediaQueryService $mediaQueries,
    ) {}

    /**
     * List approved public comments for one canonical target.
     */
    public function index(
        Request $request,
        string $target,
        string $targetId,
        CommentTargetRegistry $targets,
        QueryFilterSetFactory $filterFactory,
        ListCommentsAction $action,
        CommentProjectionFactory $projections,
    ): JsonResponse {
        $model = $targets->resolve($target, $targetId);
        $comments = $action->execute(
            $model,
            CommentActorData::anonymous(),
            $filterFactory->fromHttpQuery(
                $this->query($request),
                Comment::filterSchema(),
            ),
            $request->has('per_page') ? $request->integer('per_page') : null,
            CommentAudience::Public,
        );

        return $this->publicRead(response()->json([
            'data' => array_map(
                static fn (PublicCommentData $comment): array => $comment->toArray(),
                $projections->publicCollection(
                    new Collection($comments->items()),
                    $model,
                ),
            ),
            'meta' => $this->pagination($comments),
        ]));
    }

    /**
     * Create a public root or inherited-visibility reply.
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
        $payload = $this->creationPayload($request);
        $data = CreateCommentData::validateAndCreate($payload);

        if ($data->visibility !== CommentVisibility::Public) {
            throw ValidationException::withMessages([
                'visibility' => 'Public comment creation only accepts public visibility.',
            ]);
        }

        $model = $targets->resolve($target, $targetId);
        $comment = $action->execute(
            $model,
            $data,
            $actors->fromRequest($request),
            CommentAudience::Public,
        );

        return $this->privateResponse(response()->json([
            'data' => $projections->publicComment($comment, $model)->toArray(),
        ], $comment->wasRecentlyCreated ? 201 : 200));
    }

    /**
     * Show one approved public comment or tombstone.
     */
    public function show(
        string $comment,
        GetCommentAction $action,
        CommentProjectionFactory $projections,
        CommentTargetLocator $targets,
    ): JsonResponse {
        $comment = $action->execute(
            $comment,
            CommentActorData::anonymous(),
            CommentAudience::Public,
        );

        return $this->publicRead(response()->json([
            'data' => $projections
                ->publicComment($comment, $targets->locate($comment))
                ->toArray(),
        ]));
    }

    /**
     * Update an actor-owned comment.
     */
    public function update(
        Request $request,
        string $comment,
        CommentActorResolver $actors,
        UpdateCommentAction $action,
        CommentProjectionFactory $projections,
        CommentTargetLocator $targets,
    ): JsonResponse {
        $comment = $action->execute(
            $comment,
            UpdateCommentData::validateAndCreate($request->all()),
            $actors->fromRequest($request),
            CommentAudience::Public,
        );

        return $this->privateResponse(response()->json([
            'data' => $projections
                ->publicComment($comment, $targets->locate($comment))
                ->toArray(),
        ]));
    }

    /**
     * Soft-delete an actor-owned comment and return its safe tombstone.
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
            CommentAudience::Public,
        );
        $comment = $this->reads->findById(
            $comment,
            $actor,
            CommentAudience::Public,
        );

        return $this->privateResponse(response()->json([
            'data' => $projections
                ->publicComment($comment, $targets->locate($comment))
                ->toArray(),
        ]));
    }

    /**
     * Set one reaction and return every configured public aggregate.
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
            CommentAudience::Public,
        );
        $comment = $this->reads->findById(
            $comment,
            $actor,
            CommentAudience::Public,
            withTrashed: false,
        );

        return $this->privateResponse(response()->json([
            'data' => $projections
                ->publicComment($comment, $targets->locate($comment))
                ->toArray(),
        ]));
    }

    /**
     * Submit or reopen a report without exposing reporter or moderation state.
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
            CommentAudience::Public,
        );

        return $this->privateResponse(response()->json([
            'data' => ['reported' => true],
        ], 202));
    }

    /**
     * List viewer-independent public attachment associations.
     */
    public function attachments(
        string $comment,
        ListCommentAttachmentsAction $action,
    ): JsonResponse {
        return $this->publicRead(
            response()->json([
                'data' => $action->execute(
                    $comment,
                    CommentActorData::anonymous(),
                    CommentAudience::Public,
                )->map(
                    static fn (CommentAttachmentData $attachment): array => $attachment->toArray(),
                )->all(),
            ]),
            containsSignedUrls: true,
        );
    }

    /**
     * Attach one authorized Media record and return only its safe association projection.
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
            CommentAudience::Public,
        );
        $comment = $this->reads->findById(
            $comment,
            $actor,
            CommentAudience::Public,
            withTrashed: false,
        );
        $association = $this->mediaQueries->activeAssociation(
            $association->id,
            $comment->getMorphClass(),
            'attachments',
        );
        $attachment = $attachments->fromAssociation(
            $association,
            $comment,
            $targets->locate($comment),
            $actor,
            CommentAudience::Public,
        );

        if ($attachment === null) {
            throw (new ModelNotFoundException)->setModel(
                MediaAssociation::class,
                [$association->id],
            );
        }

        return $this->privateResponse(response()->json([
            'data' => $attachment->toArray(),
        ], 201));
    }

    /**
     * Idempotently detach only the selected comment association.
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
            CommentAudience::Public,
        );

        return $this->privateResponse(response()->json(null, 204));
    }

    /**
     * Copy a validated optional idempotency header into the mutation DTO payload.
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
     * Mark viewer-independent public reads as shared-cache compatible.
     */
    private function publicRead(
        JsonResponse $response,
        bool $containsSignedUrls = false,
    ): JsonResponse {
        $seconds = CommentsConfiguration::positiveInteger(
            'comments.cache.public_max_age',
            60,
        );

        if ($containsSignedUrls) {
            $signedLifetimeSeconds = CommentsConfiguration::positiveInteger(
                'comments.attachments.signed_url_lifetime',
                5,
            ) * 60;
            $seconds = min(
                $seconds,
                max(
                    1,
                    $signedLifetimeSeconds - self::SIGNED_URL_CACHE_SAFETY_SECONDS,
                ),
            );
        }

        return $response->header(
            'Cache-Control',
            "public, max-age={$seconds}, s-maxage={$seconds}",
        );
    }

    /**
     * Prevent viewer-aware, mutation, and signed-asset responses from being cached.
     */
    private function privateResponse(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
