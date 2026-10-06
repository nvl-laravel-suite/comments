<?php

declare(strict_types=1);

use Nvl\Comments\Tests\CommentBatchTenancyTestCase;
use Nvl\Comments\Tests\TestCase;

uses(TestCase::class)->in(__DIR__.'/Feature');
uses(TestCase::class)->in(__DIR__.'/Tenancy');
uses(CommentBatchTenancyTestCase::class)->in(__DIR__.'/BatchTenancy');
