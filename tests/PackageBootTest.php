<?php

namespace Ojcloveu\LogHub\Tests;

use Illuminate\Support\ServiceProvider;
use Ojcloveu\LogHub\LogHubServiceProvider;

final class PackageBootTest extends TestCase
{
    public function test_package_provider_loads_default_configuration(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(LogHubServiceProvider::class));
        $this->assertTrue(config('loghub-client.enabled'));
        $this->assertSame(100, config('loghub-client.delivery.batch_size'));
        $this->assertSame(storage_path('logs/loghub-spool'), config('loghub-client.spool.path'));
    }

    public function test_package_configuration_is_publishable(): void
    {
        $paths = ServiceProvider::pathsToPublish(
            LogHubServiceProvider::class,
            'loghub-client-config',
        );

        $this->assertContains(
            config_path('loghub-client.php'),
            $paths,
        );
    }
}
