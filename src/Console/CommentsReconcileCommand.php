<?php

declare(strict_types=1);

namespace Nvl\Comments\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use InvalidArgumentException;
use Nvl\Comments\Data\CommentReconciliationResultData;
use Nvl\Comments\Exceptions\CommentsException;
use Nvl\Comments\Services\CommentStateReconciler;
use Nvl\Comments\Services\CommentTargetRegistry;
use Nvl\Comments\Support\CommentsConfiguration;

/**
 * Audits comment counters and thread lineage without mutating state by default.
 */
final class CommentsReconcileCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:comments:reconcile
        {--repair : Apply race-safe repairs instead of running read-only}
        {--force : Permit repairs in production}
        {--strict : Return failure while drift remains}
        {--target= : Restrict to one target as alias:identifier}
        {--chunk= : Comments processed per chunk}
        {--format=table : Output format: table or json}';

    /** @var string */
    protected $description = 'Audit or repair denormalized Comments counters and thread lineage';

    /**
     * Run a bounded read-only audit or explicitly requested repair.
     */
    public function handle(
        CommentStateReconciler $reconciler,
        CommentTargetRegistry $targets,
    ): int {
        $format = $this->format();

        if ($format === null) {
            $this->error('The --format option must be table or json.');

            return self::FAILURE;
        }

        $repair = (bool) $this->option('repair');

        if ($repair && App::environment('production') && ! (bool) $this->option('force')) {
            return $this->renderFailure(
                'The --force option is required to repair comments in production.',
                $format,
            );
        }

        $chunkSize = $this->chunkSize();

        if ($chunkSize === null) {
            return $this->renderFailure(
                'The --chunk option must be an integer between 1 and 10000.',
                $format,
            );
        }

        try {
            [$target, $targetLabel] = $this->target($targets);
            $result = $reconciler->reconcile(
                $target,
                $chunkSize,
                $repair,
                $targetLabel,
            );
        } catch (CommentsException|InvalidArgumentException $exception) {
            return $this->renderFailure($exception->getMessage(), $format);
        }

        $this->writeResult($result, $format);

        return (bool) $this->option('strict') && ! $result->healthy
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * Resolve and validate the requested output format.
     */
    private function format(): ?string
    {
        $format = $this->option('format');

        return is_string($format) && in_array($format, ['table', 'json'], true)
            ? $format
            : null;
    }

    /**
     * Resolve and validate the configured or requested chunk size.
     */
    private function chunkSize(): ?int
    {
        $option = $this->option('chunk');

        if ($option === null || $option === '') {
            return CommentsConfiguration::positiveInteger(
                'comments.reconciliation.chunk_size',
                500,
            );
        }

        return $this->validatedChunkSize($option);
    }

    /**
     * Validate CLI string and programmatic Artisan integer option values.
     */
    private function validatedChunkSize(mixed $option): ?int
    {
        if (is_int($option)) {
            $chunkSize = $option;
        } elseif (is_string($option)
            && preg_match('/^[0-9]+$/', $option) === 1) {
            $chunkSize = (int) $option;
        } else {
            return null;
        }

        return $chunkSize >= 1 && $chunkSize <= 10_000 ? $chunkSize : null;
    }

    /**
     * Resolve an optional allowlisted target selector.
     *
     * @return array{Model|null, string|null}
     */
    private function target(CommentTargetRegistry $targets): array
    {
        $selector = $this->option('target');

        if ($selector === null || $selector === '') {
            return [null, null];
        }

        if (! str_contains($selector, ':')) {
            throw new InvalidArgumentException(
                'The --target option must use alias:identifier.',
            );
        }

        [$alias, $identifier] = explode(':', $selector, 2);
        $alias = trim($alias);
        $identifier = trim($identifier);

        if ($alias === '' || $identifier === '') {
            throw new InvalidArgumentException(
                'The --target option must use a non-empty alias:identifier.',
            );
        }

        return [$targets->resolve($alias, $identifier), "{$alias}:{$identifier}"];
    }

    /**
     * Render a reconciliation result in the selected machine or human format.
     */
    private function writeResult(
        CommentReconciliationResultData $result,
        string $format,
    ): void {
        if ($format === 'json') {
            $this->line((string) json_encode(
                $result->toArray(),
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ));

            return;
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Mode', $result->dryRun ? 'dry-run' : 'repair'],
                ['Target', $result->target ?? 'all'],
                ['Comments scanned', $result->scanned],
                ['Comments with drift', $result->drifted],
                ['Comments repaired', $result->repaired],
                ['Comments remaining', $result->remaining],
                ['Reply counter mismatches', $result->replyCountMismatches],
                ['Reaction counter mismatches', $result->reactionCountMismatches],
                ['Report counter mismatches', $result->reportCountMismatches],
                ['Open report counter mismatches', $result->openReportCountMismatches],
                ['Thread lineage mismatches', $result->threadMismatches],
                [
                    'Unrepairable thread mismatches',
                    $result->unrepairableThreadMismatches,
                ],
                [
                    'Identity fingerprint mismatches',
                    $result->identityFingerprintMismatches,
                ],
                ['Comments with missing targets', $result->missingTargetComments],
                [
                    'Invalid attachment associations',
                    $result->invalidAttachmentAssociations,
                ],
                ['Missing metadata index values', $result->missingMetadataIndexValues],
                ['Stale metadata index values', $result->staleMetadataIndexValues],
                ['Document/mention row mismatches', $result->documentMentionMismatches],
                ['Duplicate mention identities', $result->duplicateMentionIdentities],
                ['Invalid mention snapshots', $result->invalidMentionSnapshots],
                ['Orphan mention rows', $result->orphanMentionRows],
                ['Body projection mismatches', $result->bodyProjectionMismatches],
                ['Healthy', $result->healthy ? 'yes' : 'no'],
            ],
        );
    }

    /**
     * Render a command error without corrupting JSON output.
     */
    private function renderFailure(string $message, string $format): int
    {
        if ($format === 'json') {
            $this->line((string) json_encode(
                ['error' => $message],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
