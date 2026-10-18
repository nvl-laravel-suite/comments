<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests;

use Nvl\Media\Providers\MediaServiceProvider;

/** Exercises the optional Media composition with a separately unscoped root. */
abstract class OwnershipMediaTestCase extends OwnershipTestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), MediaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set(['nvl-comments.attachments.enabled' => true, 'nvl-media.migrations.enabled' => false]);
    }

    protected function adoptOwnership(): void
    {
        foreach (glob(__DIR__.'/../../media/database/migrations/*.php') ?: [] as $path) {
            (require $path)->up();
        } parent::adoptOwnership();
    }
}
