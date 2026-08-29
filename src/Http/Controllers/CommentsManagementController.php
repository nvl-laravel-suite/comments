<?php

declare(strict_types=1);

namespace Nvl\Comments\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use LogicException;
use Nvl\Comments\Actions\AnonymizeCommentAction;
use Nvl\Comments\Actions\CreateRichCommentAction;
use Nvl\Comments\Actions\DetachCommentMediaAction;
use Nvl\Comments\Actions\ListCommentAttachmentsAction;
use Nvl\Comments\Actions\ListCommentReportsAction;
use Nvl\Comments\Actions\ListCommentRevisionsAction;
use Nvl\Comments\Actions\ListModerationCommentsAction;
use Nvl\Comments\Actions\ListTargetCommentReportsAction;
use Nvl\Comments\Actions\ModerateCommentAction;
use Nvl\Comments\Actions\ResolveCommentReportAction;
use Nvl\Comments\Actions\RestoreCommentAction;
use Nvl\Comments\Actions\RestoreCommentRevisionAction;
use Nvl\Comments\Actions\SuggestCommentMentionResourcesAction;
use Nvl\Comments\Actions\UpdateRichCommentAction;
use Nvl\Comments\Contracts\CommentActorResolver;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentAttachmentData;
use Nvl\Comments\Data\CommentManagementData;
use Nvl\Comments\Data\CommentMentionSuggestionData;
use Nvl\Comments\Data\CommentReportManagementData;
use Nvl\Comments\Data\CommentTargetReportQueueData;
use Nvl\Comments\Data\Mutations\AnonymizeCommentData;
use Nvl\Comments\Data\Mutations\CreateRichCommentData;
use Nvl\Comments\Data\Mutations\ModerateCommentData;
use Nvl\Comments\Data\Mutations\ResolveCommentReportData;
use Nvl\Comments\Data\Mutations\RestoreCommentData;
use Nvl\Comments\Data\Mutations\RestoreCommentRevisionData;
use Nvl\Comments\Data\Mutations\UpdateRichCommentData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentReport;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Services\CommentAccessService;
use Nvl\Comments\Services\CommentProjectionFactory;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Services\CommentTargetLocator;
use Nvl\Comments\Services\CommentTargetRegistry;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Filterable\Http\QueryFilterSetFactory;

/**
 * Target-scoped privileged moderation, lifecycle, report, and audit endpoints.
 */
final class CommentsManagementController extends Controller
{
    public function __construct(
        private readonly CommentAccessService $access,
        private readonly CommentReadService $reads,
    ) {}

    /**
     * Return one target's actionable moderation comment queue.
     */
    public function index(
        Request $request,
        string $target,
        string $targetId,
        CommentTargetRegistry $targets,
        CommentActorResolver $actors,
        QueryFilterSetFactory $filterFactory,
        ListModerationCommentsAction $action,
        CommentProjectionFactory $projections,
    ): JsonResponse {
        $model = $targets->resolve($target, $targetId);
        $actor = $actors->fromRequest($request);
        $comments = $action->execute(
            $model,
            $actor,
            $request->has('per_page') ? $request->integer('per_page') : null,
            $filterFactory->fromHttpQuery(
                $this->query($request),
                Comment::managementFilterSchema(),
            ),
        );
        $commentData = $projections->managementCollection(
            new EloquentCollection($comments->items()),
            $model,
            $actor,
        );

        return $this->respond([
            'data' => array_map(
                static fn (CommentManagementData $comment): array => $comment->toArray(),
                array_values($commentData),
            ),
            'meta' => $this->pagination($comments),
        ]);
    }

    /**
     * Create one management-authorized rich comment.
     */
    public function storeRich(
        Request $request,
        string $target,
        string $targetId,
        CommentTargetRegistry $targets,
        CommentActorResolver $actors,
        CreateRichCommentAction $action,
        CommentProjectionFactory $projections,
    ): JsonResponse {
        $model = $targets->resolve($target, $targetId);
        $actor = $actors->fromRequest($request);
        $this->access->authorize(
            CommentAbility::Create,
            $actor,
            target: $model,
            audience: CommentAudience::Management,
        );
        $comment = $action->execute(
            $model,
            CreateRichCommentData::validateAndCreate($this->creationPayload($request)),
            $actor,
            CommentAudience::Management,
        );

        return $this->respond([
            'data' => $projections->managementComment($comment, $model, $actor)->toArray(),
        ], $comment->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Suggest management-authorized registered resources.
     */
    public function mentionSuggestions(
        Request $request,
        string $target,
        string $targetId,
        string $resource,
        CommentTargetRegistry $targets,
        CommentActorResolver $actors,
        SuggestCommentMentionResourcesAction $action,
    ): JsonResponse {
        $model = $targets->resolve($target, $targetId);
        $actor = $actors->fromRequest($request);
        $this->access->authorize(
            CommentAbility::List,
            $actor,
            target: $model,
            audience: CommentAudience::Management,
        );
        $query = $request->query('q');

        if (! is_string($query)) {
            throw ValidationException::withMessages(['q' => 'A suggestion query is required.']);
        }

        return $this->respond([
            'data' => $action->execute(
                $model,
                $actor,
                CommentAudience::Management,
                $resource,
                $query,
                $request->has('limit')
                    ? $request->integer('limit')
                    : min(20, CommentsConfiguration::positiveInteger(
                        'comments.mentions.suggestion_limit',
                        10,
                    )),
            )->map(
                static fn (CommentMentionSuggestionData $suggestion): array => $suggestion->toArray(),
            )->all(),
        ]);
    }

    /**
     * Replace one management-authorized rich comment.
     */
    public function updateRich(
        Request $request,
        string $comment,
        CommentActorResolver $actors,
        UpdateRichCommentAction $action,
        CommentProjectionFactory $projections,
        CommentTargetLocator $targets,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $authorizedComment = $this->reads->resolveById(
            $comment,
            $actor,
            CommentAudience::Management,
            CommentAbility::Update,
            withTrashed: false,
        );
        $authorizedTarget = $targets->locate($authorizedComment);
        $this->access->authorize(
            CommentAbility::Update,
            $actor,
            $authorizedComment,
            $authorizedTarget,
            CommentAudience::Management,
        );
        $comment = $action->execute(
            $authorizedComment,
            UpdateRichCommentData::validateAndCreate($request->all()),
            $actor,
            CommentAudience::Management,
        );
        $target = $targets->locate($comment);

        return $this->respond([
            'data' => $projections->managementComment($comment, $target, $actor)->toArray(),
        ]);
    }

    /**
     * Return a filterable report history for one comment.
     */
    public function reports(
        Request $request,
        string $comment,
        CommentActorResolver $actors,
        QueryFilterSetFactory $filterFactory,
        ListCommentReportsAction $action,
        CommentProjectionFactory $projections,
        CommentTargetLocator $targets,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $reports = $action->execute(
            $comment,
            $actor,
            $request->has('per_page') ? $request->integer('per_page') : null,
            $filterFactory->fromHttpQuery(
                $this->query($request),
                CommentReport::filterSchema(),
            ),
        );
        $firstReport = $reports->items()[0] ?? null;
        $commentModel = $firstReport instanceof CommentReport
            ? $firstReport->getRelation('comment')
            : null;
        $includeIdentity = $commentModel instanceof Comment
            && $projections->canViewManagementIdentity(
                $commentModel,
                $targets->locate($commentModel),
                $actor,
            );

        return $this->respond([
            'data' => array_map(
                static fn (CommentReport $report): array => CommentReportManagementData::fromModel(
                    $report,
                    $includeIdentity,
                )->toArray(),
                $reports->items(),
            ),
            'meta' => $this->pagination($reports),
        ]);
    }

    /**
     * Return one target's actionable report queue, including deleted evidence.
     */
    public function targetReports(
        Request $request,
        string $target,
        string $targetId,
        CommentTargetRegistry $targets,
        CommentActorResolver $actors,
        QueryFilterSetFactory $filterFactory,
        ListTargetCommentReportsAction $action,
        CommentProjectionFactory $projections,
    ): JsonResponse {
        $model = $targets->resolve($target, $targetId);
        $actor = $actors->fromRequest($request);
        $reports = $action->execute(
            $model,
            $actor,
            $request->has('per_page') ? $request->integer('per_page') : null,
            $filterFactory->fromHttpQuery(
                $this->query($request),
                CommentReport::filterSchema(),
            ),
        );
        $commentModels = [];

        foreach ($reports->items() as $report) {
            $comment = $report->getRelation('comment');

            if ($comment instanceof Comment) {
                $commentModels[$comment->id] = $comment;
            }
        }

        $commentData = $projections->managementCollection(
            new EloquentCollection(array_values($commentModels)),
            $model,
            $actor,
        );

        return $this->respond([
            'data' => array_map(
                function (CommentReport $report) use (
                    $actor,
                    $commentData,
                    $model,
                    $projections,
                ): array {
                    $comment = $report->getRelation('comment');

                    if (! $comment instanceof Comment
                        || ! isset($commentData[$comment->id])) {
                        throw new LogicException(
                            'A target report queue row requires its scoped comment projection.',
                        );
                    }

                    return CommentTargetReportQueueData::fromModel(
                        $report,
                        $commentData[$comment->id],
                        $projections->canViewManagementIdentity(
                            $comment,
                            $model,
                            $actor,
                        ),
                    )->toArray();
                },
                $reports->items(),
            ),
            'meta' => $this->pagination($reports),
        ]);
    }

    /**
     * Apply one optimistic moderation transition, including deleted evidence.
     */
    public function moderate(
        Request $request,
        string $comment,
        CommentActorResolver $actors,
        ModerateCommentAction $action,
        CommentProjectionFactory $projections,
        CommentTargetLocator $targets,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $comment = $action->execute(
            $comment,
            ModerateCommentData::validateAndCreate($request->all()),
            $actor,
        );
        $target = $targets->locate($comment);

        return $this->respond([
            'data' => $projections->managementComment(
                $comment,
                $target,
                $actor,
            )->toArray(),
        ]);
    }

    /**
     * Resolve or dismiss one actionable report.
     */
    public function resolveReport(
        Request $request,
        string $report,
        CommentActorResolver $actors,
        ResolveCommentReportAction $action,
        CommentProjectionFactory $projections,
        CommentTargetLocator $targets,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $report = $action->execute(
            $report,
            ResolveCommentReportData::validateAndCreate($request->all()),
            $actor,
        );
        $comment = $report->getRelation('comment');
        $includeIdentity = $comment instanceof Comment
            && $projections->canViewManagementIdentity(
                $comment,
                $targets->locate($comment),
                $actor,
            );

        return $this->respond([
            'data' => CommentReportManagementData::fromModel(
                $report,
                $includeIdentity,
            )->toArray(),
        ]);
    }

    /**
     * Restore one deleted comment under management authorization.
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
            CommentAudience::Management,
        );
        $comment = $this->reloadForManagement($comment->id, $actor);
        $target = $targets->locate($comment);

        return $this->respond([
            'data' => $projections->managementComment(
                $comment,
                $target,
                $actor,
            )->toArray(),
        ]);
    }

    /**
     * Irreversibly anonymize one comment under management authorization.
     */
    public function anonymize(
        Request $request,
        string $comment,
        CommentActorResolver $actors,
        AnonymizeCommentAction $action,
        CommentProjectionFactory $projections,
        CommentTargetLocator $targets,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $comment = $action->execute(
            $comment,
            AnonymizeCommentData::validateAndCreate($request->all()),
            $actor,
            CommentAudience::Management,
        );
        $comment = $this->reloadForManagement($comment->id, $actor);
        $target = $targets->locate($comment);

        return $this->respond([
            'data' => $projections->managementComment(
                $comment,
                $target,
                $actor,
            )->toArray(),
        ]);
    }

    /**
     * List management-authorized attachment associations.
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
                CommentAudience::Management,
            )->map(
                static fn (CommentAttachmentData $attachment): array => $attachment->toArray(),
            )->all(),
        ]);
    }

    /**
     * Idempotently detach one exact comment association.
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
            CommentAudience::Management,
        );

        return $this->respond(null, 204);
    }

    /**
     * Return separately paginated management-authorized revision history.
     */
    public function revisions(
        Request $request,
        string $comment,
        CommentActorResolver $actors,
        ListCommentRevisionsAction $action,
        CommentProjectionFactory $projections,
    ): JsonResponse {
        $revisions = $action->execute(
            $comment,
            $actors->fromRequest($request),
            $request->has('per_page') ? $request->integer('per_page') : null,
            CommentAudience::Management,
        );

        return $this->respond([
            'data' => array_map(
                fn (CommentRevision $revision): array => $projections
                    ->revision($revision, CommentAudience::Management)
                    ->toArray(),
                $revisions->items(),
            ),
            'meta' => $this->pagination($revisions),
        ]);
    }

    /**
     * Restore a selected revision as a new current revision.
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
            CommentAudience::Management,
        );
        $comment = $this->reloadForManagement($comment->id, $actor);
        $target = $targets->locate($comment);

        return $this->respond([
            'data' => $projections->managementComment(
                $comment,
                $target,
                $actor,
            )->toArray(),
        ]);
    }

    /**
     * Reload a lifecycle result with the aggregate needed by management projection.
     */
    private function reloadForManagement(
        string $commentId,
        CommentActorData $actor,
    ): Comment {
        return $this->reads->resolveById(
            $commentId,
            $actor,
            CommentAudience::Management,
            CommentAbility::Moderate,
        );
    }

    /**
     * Copy an optional idempotency header into the validated rich creation payload.
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
     * Return string-keyed HTTP query input for the allowlisted filter parser.
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
     * Return a private/no-store management response.
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
