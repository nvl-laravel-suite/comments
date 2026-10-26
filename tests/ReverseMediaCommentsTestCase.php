<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests;

use Nvl\Media\Providers\MediaServiceProvider;

/** Exercises attachment registration when Comments is discovered before Media. */
abstract class ReverseMediaCommentsTestCase extends TestCase
{
    /**
     * Reverse the normal provider order while retaining every selected runtime.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), MediaServiceProvider::class];
    }
}
