<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Support\Str;
use Nvl\Comments\Data\Mutations\AnonymizeCommentData;
use Nvl\Comments\Exceptions\InvalidCommentLifecycleException;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;

/**
 * Enforces lifecycle concurrency, audit-reason, and idempotency invariants.
 */
final class CommentLifecycleGuard
{
    /**
     * Validate a positive optimistic-lock revision.
     */
    public function expectedRevision(int $expectedRevision): void
    {
        if ($expectedRevision < 1) {
            throw new InvalidCommentLifecycleException(
                'A positive expected comment revision is required.',
            );
        }
    }

    /**
     * Validate and canonicalize an optional UUID idempotency key.
     */
    public function idempotencyKey(?string $idempotencyKey): ?string
    {
        if ($idempotencyKey === null) {
            return null;
        }

        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidCommentMutationException(
                'Comment idempotency keys must be valid UUIDs.',
            );
        }

        return Str::lower($idempotencyKey);
    }

    /**
     * Validate the required terminal anonymization audit reason.
     */
    public function anonymization(AnonymizeCommentData $data): void
    {
        $this->expectedRevision($data->expectedRevision);

        if (! mb_check_encoding($data->reason, 'UTF-8')
            || preg_match('/\S/u', $data->reason) !== 1) {
            throw new InvalidCommentLifecycleException(
                'An anonymization reason must contain valid, non-blank UTF-8 text.',
            );
        }

        if (mb_strlen($data->reason) > 2_000) {
            throw new InvalidCommentLifecycleException(
                'An anonymization reason may contain at most 2000 characters.',
            );
        }
    }
}
