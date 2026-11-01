<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Comments\Contracts\CommentBatchQueryScope;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Exceptions\CommentBatchReadException;
use Nvl\Comments\Support\CommentBatchOwnerAdmission;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Support\Owners\OwnerBatch;

/**
 * Canonical owner admission plus fixed-query counts and latest rows bounded in SQL.
 *
 * @internal
 */
final readonly class CommentBatchReader
{
    /** Retain compatible SQL policy and focused storage predicates. */
    public function __construct(private CommentBatchOwnerAdmission $owners, private CommentBatchQueryBuilder $queries, private CommentBatchQueryScope $policy) {}

    /**
     * Return native owner maps after complete admission, with at most 1,000 latest storage rows.
     *
     * @param  list<Model>  $owners
     * @return array{values: array<string, array<array-key, array<string, mixed>>>, order: list<array{type: string, id: string}>}
     */
    public function read(array $owners, CommentActor $actor, CommentAudience $audience = CommentAudience::Public, int $latestLimit = 3): array
    {
        if ($latestLimit < 1 || $latestLimit > 10) {
            throw new InvalidArgumentException('Comment latest limits must be between one and ten.');
        }
        $input = OwnerBatch::fromModels($owners);
        if ($input->isEmpty()) {
            return $this->result($input, []);
        }
        $batch = $this->owners->admit($input);
        $this->policy->authorizeOwners($batch, $actor->principal, $audience);
        $visible = $this->queries->batch($batch, $actor, $audience, $this->policy)->toBase();
        $counts = (clone $visible)->select('commentable_identity_hash')->selectRaw('COUNT(*) AS visible_count')->groupBy('commentable_identity_hash')->pluck('visible_count', 'commentable_identity_hash');
        $rows = $this->queries->latest($visible, $latestLimit)->get();
        $latest = [];
        foreach ($rows as $row) {
            $attributes = [];
            foreach (get_object_vars($row) as $column => $value) {
                if (! is_string($column)) {
                    throw new CommentBatchReadException('The latest comment row has an invalid column name.');
                }
                $attributes[$column] = $value;
            }
            $comment = (CommentModelRegistry::newInstance())->newFromBuilder($attributes);
            $summary = [
                'id' => $comment->id,
                'createdAt' => $comment->created_at->format(DATE_ATOM),
                'pinned' => $comment->is_pinned,
                'updatedAt' => $comment->updated_at->format(DATE_ATOM),
            ];
            if ($comment->anonymized_at === null) {
                $summary['body'] = $comment->body;
                $summary['format'] = $comment->format;
            }
            $latest[$comment->commentable_identity_hash][] = $summary;
        }
        $values = [];
        foreach ($batch->identities() as $identity) {
            $hash = CommentIdentity::pair($identity->type, $identity->id);
            $count = $counts->get($hash, 0);
            if ((! is_int($count) && ! is_string($count)) || ! is_numeric($count)) {
                throw new CommentBatchReadException('The visible comment aggregate is not an integer.');
            }
            $values[$identity->type][$identity->id] = [
                'visibleCount' => (int) $count,
                'latest' => $latest[$hash] ?? [],
            ];
        }

        return $this->result($batch, $values);
    }

    /**
     * Preserve every admitted identity, including empty owners, in request order.
     *
     * @param  array<string, array<array-key, array<string, mixed>>>  $values
     * @return array{values: array<string, array<array-key, array<string, mixed>>>, order: list<array{type: string, id: string}>}
     */
    private function result(OwnerBatch $batch, array $values): array
    {
        $order = [];
        foreach ($batch->identities() as $identity) {
            $order[] = ['type' => $identity->type, 'id' => $identity->id];
        }

        return ['order' => $order, 'values' => $values];
    }
}
