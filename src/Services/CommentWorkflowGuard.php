<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Nvl\Comments\Exceptions\InvalidCommentMutationException;

/**
 * Enforces workflow text invariants for native service callers.
 */
final class CommentWorkflowGuard
{
    /**
     * Validate a report reason and optional details.
     *
     * @param  array{reason: string, details: ?string}  $data
     */
    public function report(array $data): void
    {
        $this->assertText($data['reason'], 'Report reason', 100, required: true);

        if ($data['details'] !== null) {
            $this->assertText($data['details'], 'Report details', 4_000);
        }
    }

    /**
     * Validate an optional moderation reason.
     *
     * @param  array{reason: ?string}  $data
     */
    public function moderation(array $data): void
    {
        if ($data['reason'] !== null) {
            $this->assertText($data['reason'], 'Moderation reason', 2_000);
        }
    }

    /**
     * Validate the required report resolution.
     *
     * @param  array{resolution: string}  $data
     */
    public function resolution(array $data): void
    {
        $this->assertText($data['resolution'], 'Report resolution', 4_000, required: true);
    }

    private function assertText(
        string $value,
        string $label,
        int $maximumCharacters,
        bool $required = false,
    ): void {
        if (! mb_check_encoding($value, 'UTF-8')
            || ($required && preg_match('/\S/u', $value) !== 1)) {
            throw new InvalidCommentMutationException(
                "{$label} must contain valid".($required ? ', non-blank' : '').' UTF-8 text.',
            );
        }

        if (mb_strlen($value) > $maximumCharacters) {
            throw new InvalidCommentMutationException(
                "{$label} may contain at most {$maximumCharacters} characters.",
            );
        }
    }
}
