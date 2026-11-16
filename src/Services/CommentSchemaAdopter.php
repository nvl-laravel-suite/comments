<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\CommentReaction;
use Nvl\Comments\Ownership\CommentsOwnership;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Comments\Support\CommentTargetIdentifier;
use Nvl\Comments\Support\EncodedCommentContent;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\OwnerRegistry;
use stdClass;

/**
 * Stages legacy comments and reactions for a deliberate host cutover.
 *
 * @api
 *
 * @phpstan-type Options array{connection:?string,actor_type:string,owner_types:array<string,string>,actor_types:array<string,string>}
 * @phpstan-type Topology array{root:?string,depth:int,replies:int}
 * @phpstan-type StagedRow array{id:string, ...<string,mixed>}
 * @phpstan-type Review array{schema_fingerprint:string,fingerprint:string,comments:int,reactions:int,staged_comments:int,staged_reactions:int,conflicts:list<string>}
 */
final readonly class CommentSchemaAdopter
{
    private const array LegacyComment = ['id', 'commentable_type', 'commentable_id', 'parent_id', 'content', 'content_type', 'user_id', 'user_provider', 'tags', 'metadata', 'is_pinned', 'pinned_at', 'pinned_by', 'status', 'approved_at', 'approved_by', 'replies_count', 'is_edited', 'last_edited_at', 'edited_by', 'created_at', 'updated_at', 'deleted_at'];

    private const array LegacyReaction = ['id', 'comment_id', 'user_id', 'type', 'created_at', 'updated_at'];

    /** Reuse the registered owner identity and moderation policy. */
    public function __construct(private DatabaseManager $database, private OwnerRegistry $owners, private CommentAccessService $access) {}

    /**
     * Inspect the frozen legacy tables and staged target. No records are written during review.
     *
     * @param  array<array-key,mixed>  $source
     * @return Review
     */
    public function plan(Authenticatable|SystemActor $actor, array $source): array
    {
        $principal = CommentActor::from($actor);

        return $this->review($principal, $this->admit($principal, $source));
    }

    /**
     * Stage parent-first committed batches and verify the final copied rows.
     *
     * @param  array<array-key,mixed>  $source
     * @return Review
     */
    public function apply(Authenticatable|SystemActor $actor, string $fingerprint, array $source): array
    {
        $principal = CommentActor::from($actor);
        $options = $this->admit($principal, $source);
        $this->requireCommittedTarget();
        $review = $this->review($principal, $options);
        $this->assertReviewed($review, $fingerprint);
        if (! $this->reconciled($review)) {
            $this->assertReviewed($this->review($principal, $options, write: true), $fingerprint);
            $review = $this->review($principal, $options);
        }

        return $this->requireReconciled($review, $fingerprint);
    }

    /**
     * Reconcile committed comments and reactions before the host switches its integration.
     *
     * @param  array<array-key,mixed>  $source
     * @return Review
     */
    public function verify(Authenticatable|SystemActor $actor, string $fingerprint, array $source): array
    {
        $principal = CommentActor::from($actor);
        $options = $this->admit($principal, $source);
        $this->requireCommittedTarget();

        return $this->requireReconciled($this->review($principal, $options), $fingerprint);
    }

    /**
     * @param  array<array-key,mixed>  $source
     * @return Options
     */
    private function admit(CommentActor $actor, array $source): array
    {
        $this->access->authorize(CommentAbility::AdoptHistory, $actor, audience: CommentAudience::Management);
        if (CommentsOwnership::declared()) {
            throw new InvalidCommentMutationException('Stage standalone comments before the native Ownership adoption process.');
        }
        if (array_diff(array_keys($source), ['connection', 'actor_type', 'actor_types', 'owner_types']) !== []) {
            throw new InvalidCommentMutationException('Unsupported legacy comment source option.');
        }
        $connection = $source['connection'] ?? null;
        if ($connection !== null && (! is_string($connection) || trim($connection) === '')) {
            throw new InvalidCommentMutationException('A legacy comment connection must name a configured database connection.');
        }

        return [
            'connection' => $connection,
            'actor_type' => $this->label($source['actor_type'] ?? null, 100, 'actor type'),
            'actor_types' => $this->mapping($source['actor_types'] ?? []),
            'owner_types' => $this->mapping($source['owner_types'] ?? []),
        ];
    }

    /** @return array<string,string> */
    private function mapping(mixed $values): array
    {
        if (! is_array($values)) {
            throw new InvalidCommentMutationException('Legacy identity mappings require a map.');
        }
        $resolved = [];
        foreach ($values as $key => $value) {
            $resolved[$this->label($key, 255, 'mapping key')] = $this->label($value, 100, 'mapping value');
        }

        return $resolved;
    }

    private function requireCommittedTarget(): void
    {
        if (CommentModelRegistry::newInstance()->getConnection()->transactionLevel() !== 0) {
            throw new InvalidCommentMutationException('Legacy comment adoption requires a committed target outside existing transactions.');
        }
    }

    /**
     * @param  Options  $options
     * @return Review
     */
    private function review(CommentActor $actor, array $options, bool $write = false): array
    {
        $origin = $this->database->connection($options['connection']);
        $model = CommentModelRegistry::newInstance();
        $destination = $model->getConnection();
        $commentTable = $model->getTable();
        $reactionTable = (new CommentReaction)->getTable();
        $schema = [
            $this->schema($origin, 'comments', self::LegacyComment, legacy: true, countColumn: true),
            $this->schema($origin, 'comments_reactions', self::LegacyReaction, legacy: true),
            $this->schema($destination, $commentTable, ['id', 'commentable_identity_hash', 'body', 'document', 'approved_at', 'pinned_at', 'edited_by']),
            $this->schema($destination, $reactionTable, ['id', 'comment_id', 'actor_identity_hash', 'type_hash']),
        ];
        $schemaFingerprint = hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR));
        $digest = hash_init('sha256');
        hash_update($digest, $schemaFingerprint);
        $graph = $this->topology($origin);
        $conflicts = [];
        $orphan = $origin->table('comments_reactions as r')->useWritePdo()->leftJoin('comments as c', 'c.id', '=', 'r.comment_id')->whereNull('c.id')->value('r.id');
        if (is_string($orphan)) {
            $conflicts[] = "Legacy reaction [{$orphan}] has no comment.";
        }
        $stagedComments = $stagedReactions = $reactions = 0;
        foreach (array_chunk(array_keys($graph), 40) as $ids) {
            $legacyComments = $origin->table('comments')->useWritePdo()->whereIn('id', $ids)->get()->keyBy('id');
            $legacyReactions = $origin->table('comments_reactions')->useWritePdo()->whereIn('comment_id', $ids)->orderBy('id')->get()->groupBy('comment_id');
            $commentRows = [];
            $reactionRows = [];
            $targets = [];
            foreach ($ids as $id) {
                $legacy = $legacyComments->get($id);
                if (! $legacy instanceof stdClass) {
                    throw new InvalidCommentMutationException('The legacy comment source changed during review.');
                }
                $owner = $this->owner($legacy, $options, $actor, $targets);
                $commentsReactions = $legacyReactions->get($id, collect());
                $comment = $this->comment($legacy, $graph[$id], $owner, $options, $commentsReactions->count());
                $commentRows[] = $comment;
                hash_update($digest, json_encode([$legacy, $comment], JSON_THROW_ON_ERROR)."\n");
                $seen = [];
                foreach ($commentsReactions as $original) {
                    $reaction = $this->reaction($original, $id, $options['actor_type']);
                    $identity = $reaction['actor_identity_hash'].$reaction['type_hash'];
                    if (isset($seen[$identity])) {
                        $conflicts[] = "Legacy comment [{$id}] has duplicate reactions [{$seen[$identity]}, {$reaction['id']}].";
                    }
                    $seen[$identity] = $reaction['id'];
                    $reactionRows[] = $reaction;
                    hash_update($digest, json_encode($reaction, JSON_THROW_ON_ERROR)."\n");
                }
            }
            $reactions += count($reactionRows);
            $pendingComments = $this->pending($destination, $commentTable, $commentRows, $conflicts);
            $pendingReactions = $this->pending($destination, $reactionTable, $reactionRows, $conflicts);
            if ($write && $conflicts !== []) {
                throw new InvalidCommentMutationException('Comment adoption conflicts: '.$conflicts[0]);
            }
            if ($write && ($pendingComments !== [] || $pendingReactions !== [])) {
                $destination->transaction(static function () use ($destination, $commentTable, $reactionTable, $pendingComments, $pendingReactions): void {
                    foreach ([$commentTable => $pendingComments, $reactionTable => $pendingReactions] as $table => $rows) {
                        foreach (array_chunk($rows, 25) as $batch) {
                            $destination->table($table)->insert($batch);
                        }
                    }
                });
            }
            $stagedComments += count($commentRows) - ($write ? 0 : count($pendingComments));
            $stagedReactions += count($reactionRows) - ($write ? 0 : count($pendingReactions));
        }

        if ($destination->table($commentTable)->useWritePdo()->count() !== $stagedComments || $destination->table($reactionTable)->useWritePdo()->count() !== $stagedReactions) {
            $conflicts[] = 'Native Comment staging contains rows outside the reviewed legacy graph.';
        }

        return ['schema_fingerprint' => $schemaFingerprint, 'fingerprint' => hash_final($digest), 'comments' => count($graph), 'reactions' => $reactions,
            'staged_comments' => $stagedComments, 'staged_reactions' => $stagedReactions, 'conflicts' => $conflicts];
    }

    /**
     * @param  list<string>  $required
     * @return array<string,mixed>
     */
    private function schema(Connection $connection, string $table, array $required, bool $legacy = false, bool $countColumn = false): array
    {
        $schema = $connection->getSchemaBuilder();
        $columns = $schema->getColumns($table);
        $names = array_column($columns, 'name');
        if ($countColumn) {
            $required[] = in_array('reactions_count', $names, true) ? 'reactions_count' : 'likes_count';
        }
        if (array_diff($required, $names) !== [] || ($legacy && array_diff($names, $required) !== []) || ! $schema->hasIndex($table, ['id'], 'primary')) {
            throw new InvalidCommentMutationException("Legacy comment adoption table [{$table}] has an incompatible schema.");
        }

        return ['connection' => $connection->getName(), 'database' => $connection->getDatabaseName(), 'driver' => $connection->getDriverName(),
            'table' => $connection->getTablePrefix().$table, 'columns' => $columns, 'indexes' => $schema->getIndexes($table), 'foreign_keys' => $schema->getForeignKeys($table)];
    }

    /** @return array<string,Topology> */
    private function topology(Connection $origin): array
    {
        $entries = $children = $queue = [];
        foreach ($origin->table('comments')->useWritePdo()->select(['id', 'parent_id', 'commentable_type', 'commentable_id', 'deleted_at'])->lazyById(250) as $row) {
            $id = $this->uuid($row->id);
            $parent = $row->parent_id === null ? null : $this->uuid($row->parent_id);
            $entries[$id] = $row;
            if ($parent === null) {
                $queue[] = $id;
            } else {
                $children[$parent][] = $id;
            }
        }

        $graph = [];
        for ($index = 0; $index < count($queue); $index++) {
            $id = $queue[$index];
            $row = $entries[$id];
            $parent = is_string($row->parent_id) ? $row->parent_id : null;
            $replies = 0;
            foreach ($children[$id] ?? [] as $child) {
                $childRow = $entries[$child];
                if ($childRow->commentable_id !== $row->commentable_id || $childRow->commentable_type !== $row->commentable_type) {
                    throw new InvalidCommentMutationException("Legacy reply [{$child}] crosses its parent's target identity.");
                }
                $replies += $childRow->deleted_at === null ? 1 : 0;
                $queue[] = $child;
            }
            $graph[$id] = ['root' => $parent === null ? null : ($graph[$parent]['root'] ?? $parent), 'depth' => $parent === null ? 0 : $graph[$parent]['depth'] + 1, 'replies' => $replies];
        }
        if (count($graph) !== count($entries)) {
            throw new InvalidCommentMutationException('The legacy comment graph contains a missing parent or cycle.');
        }

        return $graph;
    }

    /**
     * @param  Options  $options
     * @param  array<string,array{type:string,id:string}>  $cache
     * @return array{type:string,id:string}
     */
    private function owner(stdClass $row, array $options, CommentActor $actor, array &$cache): array
    {
        $type = $this->label($row->commentable_type, 255, 'legacy owner type');
        $id = $this->uuid($row->commentable_id);
        $key = CommentIdentity::pair($type, $id);
        if (! isset($cache[$key])) {
            $class = $this->owners->model($options['owner_types'][$type] ?? $type);
            $owner = (new $class)->newQuery()->useWritePdo()->find($id);
            if (! $owner instanceof Model) {
                throw new InvalidCommentMutationException("Legacy comment target [{$id}] is missing.");
            }
            $this->access->authorize(CommentAbility::AdoptHistory, $actor, target: $owner, audience: CommentAudience::Management);
            $cache[$key] = CommentTargetIdentifier::canonical($owner);
        }

        return $cache[$key];
    }

    /**
     * @param  Topology  $tree
     * @param  array{type:string,id:string}  $owner
     * @param  Options  $options
     * @return StagedRow
     */
    private function comment(stdClass $row, array $tree, array $owner, array $options, int $reactions): array
    {
        $id = $this->uuid($row->id);
        $content = $row->content;
        if (! is_string($content) || ! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            throw new InvalidCommentMutationException("Legacy comment [{$id}] has invalid text content.");
        }
        $format = CommentFormat::Plain;
        $body = match ($row->content_type) {
            'text', 'markdown' => $content,
            'html' => EncodedCommentContent::capture(new HtmlCommentCodec, $content)->body,
            'rich' => EncodedCommentContent::capture(new RichBlockCommentCodec, $content)->body,
            default => throw new InvalidCommentMutationException("Legacy comment [{$id}] has an unsupported content format."),
        };
        if ($row->content_type === 'markdown') {
            $format = CommentFormat::Markdown;
        }
        $status = match ($row->status) {
            'active' => CommentStatus::Approved,
            'pending' => CommentStatus::Pending,
            'hidden' => CommentStatus::Hidden,
            'flagged' => CommentStatus::Flagged,
            default => throw new InvalidCommentMutationException("Legacy comment [{$id}] has an unsupported moderation status."),
        };
        $actorId = $row->user_id === null ? null : $this->uuid($row->user_id);
        $actorType = $actorId === null ? null : (is_string($row->user_provider) ? ($options['actor_types'][$row->user_provider] ?? $options['actor_type']) : $options['actor_type']);
        $payload = (array) $row;
        unset($payload['id'], $payload['commentable_id'], $payload['parent_id'], $payload['user_id'], $payload['pinned_by'], $payload['approved_by'], $payload['edited_by']);
        $tags = is_string($row->tags) ? array_values(array_unique(array_filter(array_map('trim', explode(',', $row->tags)), static fn (string $tag): bool => $tag !== ''))) : null;
        [$pinnedBy, $pinnerType, $pinnedAt] = $this->attribution($row->pinned_by, $row->pinned_at, $options['actor_type']);
        [$approvedBy, $approverType, $approvedAt] = $this->attribution($row->approved_by, $row->approved_at, $options['actor_type']);
        [$editedBy, $editorType, $editedAt] = $this->attribution($row->edited_by, $row->last_edited_at, $options['actor_type']);

        return ['id' => $id, 'commentable_type' => $owner['type'], 'commentable_id' => $owner['id'], 'commentable_identity_hash' => CommentIdentity::pair($owner['type'], $owner['id']),
            'parent_id' => $row->parent_id, 'root_id' => $tree['root'], 'depth' => $tree['depth'], 'reply_count' => $tree['replies'], 'reaction_count' => $reactions,
            'actor_type' => $actorType, 'actor_id' => $actorId, 'actor_identity_hash' => CommentIdentity::actor($actorType, $actorId), 'body' => $body,
            'format' => $format->value, 'status' => $status->value, 'status_hash' => CommentIdentity::value('comment-status', $status),
            'visibility' => CommentVisibility::Public->value, 'visibility_hash' => CommentIdentity::value('comment-visibility', CommentVisibility::Public),
            'tags' => $tags === null ? null : json_encode($tags, JSON_THROW_ON_ERROR), 'revision' => 1, 'is_pinned' => (bool) $row->is_pinned,
            'document' => json_encode(['codec' => 'legacy-comment', 'version' => 1, 'payload' => $payload], JSON_THROW_ON_ERROR),
            'pinned_by' => $pinnedBy, 'pinned_by_type' => $pinnerType, 'pinned_at' => $pinnedAt,
            'approved_by' => $approvedBy, 'approved_by_type' => $approverType, 'approved_at' => $approvedAt,
            'edited_by' => $editedBy, 'edited_by_type' => $editorType, 'edited_at' => $editedAt,
            'created_at' => $this->timestamp($row->created_at), 'updated_at' => $this->timestamp($row->updated_at), 'deleted_at' => $this->timestamp($row->deleted_at)];
    }

    /** @return array{id:string, actor_identity_hash:string, type_hash:string, ...<string,mixed>} */
    private function reaction(stdClass $row, string $comment, string $actorType): array
    {
        $actorId = $this->uuid($row->user_id);
        $type = $this->label($row->type, 64, 'reaction type');

        return ['id' => $this->uuid($row->id), 'comment_id' => $comment, 'actor_type' => $actorType, 'actor_id' => $actorId,
            'actor_identity_hash' => CommentIdentity::pair($actorType, $actorId), 'type' => $type, 'type_hash' => CommentIdentity::value('reaction-type', $type),
            'created_at' => $this->timestamp($row->created_at), 'updated_at' => $this->timestamp($row->updated_at)];
    }

    /** @return array{string|null,string|null,string|null} */
    private function attribution(mixed $actor, mixed $at, string $type): array
    {
        return [$actor === null ? null : $this->uuid($actor), $actor === null ? null : $type, $this->timestamp($at)];
    }

    private function uuid(mixed $value): string
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw new InvalidCommentMutationException('Legacy comment records require original UUID identifiers.');
        }

        return $value;
    }

    private function label(mixed $value, int $maximum, string $field): string
    {
        if (! is_string($value) || trim($value) === '' || strlen($value) > $maximum || str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidCommentMutationException("Invalid {$field} in legacy comment adoption.");
        }

        return $value;
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/', $value) !== 1) {
            throw new InvalidCommentMutationException('Legacy comment timestamps must use the canonical database datetime format.');
        }

        return $value;
    }

    /**
     * @param  list<StagedRow>  $rows
     * @param  list<string>  $conflicts
     * @return list<StagedRow>
     */
    private function pending(Connection $connection, string $table, array $rows, array &$conflicts): array
    {
        $pending = [];
        foreach (array_chunk($rows, 500) as $chunk) {
            $existing = $connection->table($table)->useWritePdo()->whereIn('id', array_column($chunk, 'id'))->get()->keyBy('id');
            foreach ($chunk as $expected) {
                $stored = $existing->get($expected['id']);
                if (! $stored instanceof stdClass) {
                    $pending[] = $expected;

                    continue;
                }
                foreach ($expected as $field => $value) {
                    $actual = $stored->{$field};
                    if (is_bool($value) ? (bool) $actual !== $value : $actual !== $value) {
                        $conflicts[] = "Staged comment row [{$expected['id']}] differs in [{$field}].";

                        break;
                    }
                }
            }
        }

        return $pending;
    }

    /** @param Review $review */
    private function assertReviewed(array $review, string $fingerprint): void
    {
        if (! hash_equals($review['fingerprint'], $fingerprint)) {
            throw new InvalidCommentMutationException('Legacy comment schema or data changed. Review a new plan.');
        }
        if ($review['conflicts'] !== []) {
            throw new InvalidCommentMutationException('Comment adoption conflicts: '.$review['conflicts'][0]);
        }
    }

    /** @param Review $review */
    private function reconciled(array $review): bool
    {
        return $review['comments'] === $review['staged_comments'] && $review['reactions'] === $review['staged_reactions'];
    }

    /**
     * @param  Review  $review
     * @return Review
     */
    private function requireReconciled(array $review, string $fingerprint): array
    {
        $this->assertReviewed($review, $fingerprint);
        if (! $this->reconciled($review)) {
            throw new InvalidCommentMutationException('Legacy comments are not fully staged. Resume the reviewed adoption.');
        }

        return $review;
    }
}
