<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Enums\CommentReportStatus;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentReport;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Support\Enums\PublicActor;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/**
 * Audience-scoped native comment reads and bounded owner summaries.
 *
 * @api
 */
final readonly class CommentReader
{
    public function __construct(
        private CommentAccessService $access,
        private CommentBatchReader $batch,
        private CommentReadService $reads,
        private CommentTargetLocator $targets,
        private CommentMetadataRegistry $metadata,
        private OwnershipBoundary $boundary,
        private CommentMentionProjectionFactory $projections,
    ) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function paginate(
        Model $target,
        Authenticatable|SystemActor|PublicActor $actor,
        CommentAudience $audience = CommentAudience::Public,
        ?int $perPage = null,
        ?string $parentId = null,
    ): LengthAwarePaginator {
        $target = $this->targets->reload($target);
        $identity = CommentActor::from($actor);
        $query = $this->reads->query($target, $identity, $audience, withTrashed: false);
        if ($parentId !== null) {
            $this->reads->resolve($target, $parentId, $identity, $audience, CommentAbility::View, withTrashed: false);
            $query->where('parent_id', $parentId);
        } else {
            $query->whereNull('parent_id');
        }

        $pageSize = $this->pageSize($perPage);
        if ($parentId !== null) {
            $pageSize = min($pageSize, CommentsConfiguration::positiveInteger('nvl-comments.threading.maximum_replies_per_page', 100));
        }

        $page = $query->orderByDesc('is_pinned')->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($pageSize);
        $comments = $page->getCollection();
        $replyCounts = $this->reads->replyCounts($comments, $target, $identity, $audience);
        $projections = $this->projections->project($comments, $target, $identity, $audience);

        return $page->through(fn (Comment $comment): array => $this->summary($comment, $audience, $replyCounts[$comment->id] ?? 0, $projections[$comment->id]));
    }

    /**
     * @param  list<Model>  $owners
     * @return array<string, mixed>
     */
    public function forOwners(
        array $owners,
        Authenticatable|SystemActor|PublicActor $actor,
        CommentAudience $audience = CommentAudience::Public,
        int $latestLimit = 3,
    ): array {
        return $this->batch->read($owners, CommentActor::from($actor), $audience, $latestLimit);
    }

    /** @return array<string, mixed>|null */
    public function latest(
        Model $target,
        Authenticatable|SystemActor|PublicActor $actor,
        CommentAudience $audience = CommentAudience::Public,
    ): ?array {
        $target = $this->targets->reload($target);
        $identity = CommentActor::from($actor);
        $comment = $this->reads->query($target, $identity, $audience, withTrashed: false)
            ->orderByDesc('created_at')->orderByDesc('id')->first();

        if (! $comment instanceof Comment) {
            return null;
        }
        $comments = new Collection([$comment]);
        $replyCounts = $this->reads->replyCounts($comments, $target, $identity, $audience);

        return $this->summary($comment, $audience, $replyCounts[$comment->id] ?? 0,
            $this->projections->project($comments, $target, $identity, $audience)[$comment->id]);
    }

    /** @return LengthAwarePaginator<int, array{id: string, revision: int, body: string, format: CommentFormat, locale: ?string, tags: list<string>|null, metadata: list<array{namespace:string,values:array<string,mixed>}>,document:array<string,mixed>|null,mentions:list<array<string,mixed>>}> */
    public function revisions(
        Comment|string $comment,
        Authenticatable|SystemActor|PublicActor $actor,
        CommentAudience $audience = CommentAudience::Public,
        ?int $perPage = null,
    ): LengthAwarePaginator {
        $identity = CommentActor::from($actor);
        $comment = $this->reads->resolveById($comment, $identity, $audience, CommentAbility::ViewHistory);
        $target = $this->targets->locate($comment);
        $this->access->authorize(
            CommentAbility::ViewHistory,
            $identity,
            $comment,
            $target,
            $audience,
            asNotFound: $audience !== CommentAudience::Management,
        );

        $page = $this->boundary->query(CommentRevision::query(), 'comments.revisions')
            ->where('comment_id', $comment->id)
            ->orderByDesc('revision')->orderByDesc('id')
            ->paginate($this->pageSize($perPage));
        $projections = $this->projections->project($page->getCollection(), $target, $identity, $audience);

        return $page->through(fn (CommentRevision $revision): array => [
            'id' => $revision->id,
            'revision' => $revision->revision,
            'body' => $revision->body,
            'format' => $revision->format,
            'locale' => $revision->locale,
            'tags' => $revision->tags,
            'metadata' => $this->metadata->project($revision->metadata, $audience),
            'document' => $projections[$revision->id]['document'],
            'mentions' => $projections[$revision->id]['mentions'],
        ]);
    }

    /** @return LengthAwarePaginator<int, array{id: string, reason: string, details: ?string, status: CommentReportStatus, resolution: ?string}> */
    public function reports(
        Comment|string $comment,
        Authenticatable|SystemActor $actor,
        ?int $perPage = null,
    ): LengthAwarePaginator {
        $identity = CommentActor::from($actor);
        $comment = $this->reads->resolveById(
            $comment,
            $identity,
            CommentAudience::Management,
            CommentAbility::Moderate,
        );
        $this->access->authorize(
            CommentAbility::Moderate,
            $identity,
            $comment,
            $this->targets->locate($comment),
            CommentAudience::Management,
            context: ['operation' => 'list_reports'],
        );

        return $this->boundary->query(CommentReport::query(), 'comments.reports')
            ->where('comment_id', $comment->id)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($this->pageSize($perPage))
            ->through(static fn (CommentReport $report): array => [
                'id' => $report->id,
                'reason' => $report->reason,
                'details' => $report->details,
                'status' => $report->status,
                'resolution' => $report->resolution,
            ]);
    }

    private function pageSize(?int $perPage): int
    {
        $maximum = CommentsConfiguration::positiveInteger('nvl-comments.pagination.maximum', 100);
        $default = CommentsConfiguration::positiveInteger('nvl-comments.pagination.default', 25);

        return max(1, min($maximum, $perPage ?? $default));
    }

    /**
     * @param  array{document:array<string,mixed>|null,mentions:list<array<string,mixed>>}  $projection
     * @return array<string, mixed>
     */
    private function summary(Comment $comment, CommentAudience $audience, int $replyCount, array $projection): array
    {
        $result = [
            'id' => $comment->id,
            'parentId' => $comment->parent_id,
            'revision' => $comment->revision,
            'createdAt' => $comment->created_at->format(DATE_ATOM),
            'pinned' => $comment->is_pinned,
            'replyCount' => $replyCount,
            'reactionCount' => $comment->reaction_count,
            'metadata' => $this->metadata->project($comment->metadata, $audience),
        ];
        if ($comment->anonymized_at === null) {
            $result['body'] = $comment->body;
            $result['format'] = $comment->format;
        }
        if ($comment->status !== CommentStatus::Approved) {
            $result['status'] = $comment->status;
        }

        return $result + $projection;
    }
}
