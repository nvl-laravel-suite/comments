<?php

declare(strict_types=1);

namespace Nvl\Comments\Console;

use Illuminate\Console\Command;
use Nvl\Comments\Services\CommentsDoctor;

/**
 * Renders the package-owned read-only installation diagnostics.
 */
final class CommentsDoctorCommand extends Command
{
    protected $signature = 'nvl:comments:doctor {--strict} {--format=text}';

    /** @var string */
    protected $description = 'Inspect the NVL Comments installation without changing state';

    /**
     * Inspect every required Comments installation boundary and render the report.
     */
    public function handle(CommentsDoctor $doctor): int
    {

        $checks = $doctor->inspect();
        $healthy = $checks['healthy'];
        $migrationOwnershipReady = $checks['migrations.ownership']['passed'];

        if ($this->option('format') === 'json') {
            $this->line((string) json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $check => $value) {
                $this->line(sprintf(
                    '%-32s %s',
                    $check,
                    json_encode($value, JSON_THROW_ON_ERROR),
                ));
            }
        }

        $strictFailure = (bool) $this->option('strict')
            && (! $healthy || ! $migrationOwnershipReady);

        return $strictFailure ? self::FAILURE : self::SUCCESS;
    }
}
