<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Comments\Contracts\CommentBatchQueryScope;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\LatestCommentSummaryData;
use Nvl\Comments\Data\OwnerCommentSummaries;
use Nvl\Comments\Data\OwnerCommentSummaryData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Exceptions\CommentBatchReadException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Support\CommentBatchOwnerAdmission;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Support\Owners\OwnerResultMap;

/** Canonical owner admission plus fixed-query counts and latest rows bounded in SQL. */
final readonly class CommentBatchReader
{
    /** Retain compatible SQL policy and focused storage predicates. */
    public function __construct(private CommentBatchOwnerAdmission $owners, private CommentBatchQueryBuilder $queries, private CommentBatchQueryScope $policy) {}

    /**
     * Return DTO maps after complete admission, with at most 1,000 latest storage rows.
     *
     * @param  list<Model>  $owners
     */
    public function read(array $owners, CommentActorData $actor, CommentAudience $audience = CommentAudience::Public, int $latestLimit = 3): OwnerCommentSummaries
    {
        if ($latestLimit < 1 || $latestLimit > 10) {
            throw new InvalidArgumentException('Comment latest limits must be between one and ten.');
        }
        $input = OwnerBatch::fromModels($owners);
        if ($input->isEmpty()) {
            return $this->result($input, []);
        }
        $batch = $this->owners->admit($input);
        $this->policy->authorizeOwners($batch, $actor, $audience);
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
            $comment = (new Comment)->newFromBuilder($attributes);
            $latest[$comment->commentable_identity_hash][] = LatestCommentSummaryData::fromModel($comment);
        }
        $values = [];
        foreach ($batch->identities() as $identity) {
            $hash = CommentIdentity::pair($identity->type, $identity->id);
            $count = $counts->get($hash, 0);
            if ((! is_int($count) && ! is_string($count)) || ! is_numeric($count)) {
                throw new CommentBatchReadException('The visible comment aggregate is not an integer.');
            }
            $values[$identity->type][$identity->id] = new OwnerCommentSummaryData((int) $count, $latest[$hash] ?? []);
        }

        return $this->result($batch, $values);
    }

    /**
     * Require a DTO for each admitted native identity before serializing maps.
     *
     * @param  array<string, array<array-key, OwnerCommentSummaryData>>  $values
     */
    private function result(OwnerBatch $batch, array $values): OwnerCommentSummaries
    {
        return OwnerCommentSummaries::fromMap(new OwnerResultMap($batch, $values));
    }
}
