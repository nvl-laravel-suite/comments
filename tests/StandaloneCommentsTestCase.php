<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests;

use Nvl\Comments\Providers\CommentsServiceProvider;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Support\Providers\LocaleServiceProvider;

/** Exercises required Comments providers without discovering optional integrations. */
abstract class StandaloneCommentsTestCase extends TestCase
{
    /**
     * Return only the required providers, including their reverse discovery order.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LocaleServiceProvider::class, CommentsServiceProvider::class, FilterableServiceProvider::class, DataServiceProvider::class];
    }
}
