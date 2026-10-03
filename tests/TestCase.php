<?php

namespace Ojcloveu\LogHub\Tests;

use Ojcloveu\LogHub\LogHubServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [LogHubServiceProvider::class];
    }
}
