<?php

namespace Ojcloveu\LogHub;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Factory;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Monolog\Logger;
use Ojcloveu\LogHub\Console\FlushLogHubCommand;
use Ojcloveu\LogHub\Delivery\SpoolFlusher;
use Ojcloveu\LogHub\Http\Middleware\AssignRequestId;
use Ojcloveu\LogHub\Logging\LogHubHandler;
use Ojcloveu\LogHub\Spool\SpoolWriter;

final class LogHubServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/loghub-client.php', 'loghub-client');

        $this->app->singleton(SpoolWriter::class, fn (): SpoolWriter => new SpoolWriter(
            (string) config('loghub-client.spool.path'),
            (int) config('loghub-client.spool.max_file_bytes'),
            (int) config('loghub-client.spool.max_total_bytes'),
            (int) config('loghub-client.spool.lease_stale_seconds'),
        ));
        $this->app->singleton(SpoolFlusher::class, fn ($app): SpoolFlusher => new SpoolFlusher(
            spool: $app->make(SpoolWriter::class),
            http: $app->make(Factory::class),
            endpoint: (string) config('loghub-client.endpoint'),
            apiKey: config('loghub-client.api_key'),
            batchSize: (int) config('loghub-client.delivery.batch_size'),
            connectTimeout: (float) config('loghub-client.delivery.connect_timeout_seconds'),
            timeout: (float) config('loghub-client.delivery.timeout_seconds'),
            maximumRetrySeconds: (int) config('loghub-client.delivery.retry_max_seconds'),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/loghub-client.php' => config_path('loghub-client.php'),
        ], 'loghub-client-config');

        $this->app->make(LogManager::class)->extend('loghub', function ($app, array $config): Logger {
            $handler = new LogHubHandler(
                spool: $app->make(SpoolWriter::class),
                maxRecordBytes: (int) config('loghub-client.spool.max_record_bytes'),
                environment: config('loghub-client.environment'),
                level: $config['level'] ?? config('loghub-client.level'),
                bubble: (bool) ($config['bubble'] ?? true),
            );

            return new Logger('loghub', [$handler]);
        });

        if ((bool) config('loghub-client.request_id.enabled')) {
            $this->app->afterResolving(Kernel::class, static function (Kernel $kernel): void {
                if (method_exists($kernel, 'prependMiddleware')) {
                    $kernel->prependMiddleware(AssignRequestId::class);
                }
            });
        }

        if ($this->app->runningInConsole()) {
            $this->commands([FlushLogHubCommand::class]);

            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('loghub:flush')
                    ->everyMinute()
                    ->withoutOverlapping(5)
                    ->when(fn (): bool => (bool) config('loghub-client.schedule.enabled'));
            });
        }
    }
}
