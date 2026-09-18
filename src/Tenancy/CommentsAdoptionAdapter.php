<?php

declare(strict_types=1);

namespace Nvl\Comments\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migrator;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentMention;
use Nvl\Comments\Models\CommentMetadataValue;
use Nvl\Comments\Models\CommentReaction;
use Nvl\Comments\Models\CommentReport;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionBoundary;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Backfills the reviewed Comment root mapping and derives every discussion child. */
final readonly class CommentsAdoptionAdapter implements TenantAdoptionAdapter
{
    public function __construct(
        private Migrator $migrator,
        private TenantAdoptionBoundary $adoption,
        private TenantResourceRegistry $resources,
        private CommentTenantParentResolver $targets,
    ) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['comments.comments', 'comments.reactions', 'comments.revisions', 'comments.reports', 'comments.metadata', 'comments.mentions'];
    }

    public function prepare(TenantAdoptionPlan $plan): void
    {
        $this->adoption->connection($plan, 'comments.comments');
        $this->migrator->usingConnection(
            $plan->connection,
            fn () => $this->migrator->run([dirname(__DIR__, 2).'/database/tenancy-migrations'], ['force' => true]),
        );
    }

    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $connection = $this->adoption->connection($plan, 'comments.comments');
        $assignments = $this->adoption->assignments($plan, 'comments.comments', $cursor, $limit);
        $connection->transaction(function () use ($assignments, $connection): void {
            foreach ($assignments as $assignment) {
                $ownership = $this->adoption->ownership($assignment, 'comments.comments');
                $connection->table((new Comment)->getTable())
                    ->where('id', $assignment->recordId)
                    ->update($ownership);
                foreach ($this->children() as $child) {
                    $connection->table($child->getTable())
                        ->where('comment_id', $assignment->recordId)
                        ->update(['tenant_id' => $ownership['tenant_id']]);
                }
            }
        });

        return $this->adoption->result($assignments);
    }

    /** @phpstan-impure */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $connection = $this->adoption->connection($plan, 'comments.comments');
        $errors = [];
        $commentTable = (new Comment)->getTable();
        foreach ([new Comment, ...$this->children()] as $model) {
            if (! $connection->getSchemaBuilder()->hasColumn($model->getTable(), 'tenant_id')
                || $connection->table($model->getTable())->whereNull('tenant_id')->exists()) {
                $errors[] = $model->getTable().'.tenant_id';
            }
        }
        foreach ($this->children() as $child) {
            if ($connection->table($child->getTable().' as child')
                ->join($commentTable.' as comment', 'comment.id', '=', 'child.comment_id')
                ->whereColumn('child.tenant_id', '!=', 'comment.tenant_id')
                ->exists()) {
                $errors[] = $child->getTable().'.ownership';
            }
        }
        foreach ($connection->table($commentTable)->orderBy('id')->limit(101)->get() as $row) {
            if (! is_string($row->commentable_type ?? null)
                || ! is_string($row->commentable_id ?? null)
                || ! is_string($row->tenant_id ?? null)
                || ! is_string($row->id ?? null)) {
                $errors[] = 'comments.target_ownership:invalid-row';

                continue;
            }
            try {
                $types = $this->targets->types();
                $class = $types[$row->commentable_type] ?? null;
                $target = is_string($class) ? (new $class)->newQueryWithoutScopes()->find($row->commentable_id) : null;
                if (! $target instanceof Model || $this->resources->forModel($target)->key === ''
                    || $target->getAttribute('tenant_id') !== $row->tenant_id) {
                    $errors[] = 'comments.target_ownership:'.$row->id;
                }
            } catch (\Throwable) {
                $errors[] = 'comments.target_ownership:'.$row->id;
            }
            if (count($errors) >= 100) {
                break;
            }
        }

        return new TenantVerification(array_slice(array_unique($errors), 0, 100));
    }

    public function activate(TenantAdoptionPlan $plan): void
    {
        $this->assertVerified($plan, 'Comments tenant ownership did not verify.');
        $this->migrator->usingConnection(
            $plan->connection,
            fn () => $this->migrator->run([dirname(__DIR__, 2).'/database/tenancy'], ['force' => true]),
        );
        $this->assertVerified($plan, 'Comments tenant ownership failed after constraint activation.');
    }

    /** Require a fresh persisted verification at one activation checkpoint. */
    private function assertVerified(TenantAdoptionPlan $plan, string $message): void
    {
        if ($this->verify($plan)->errors !== []) {
            throw new TenantBoundaryViolation($message);
        }
    }

    /** @return list<Model> */
    private function children(): array
    {
        return [new CommentReaction, new CommentRevision, new CommentReport, new CommentMetadataValue, new CommentMention];
    }
}
