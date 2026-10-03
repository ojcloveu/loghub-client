<?php

namespace Ojcloveu\LogHub\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Ojcloveu\LogHub\Jobs\FlushLogHubSpool;

final class FlushOrchestrationTest extends TestCase
{
    public function test_filesystem_only_synchronous_flushing_is_the_default(): void
    {
        $this->assertFalse(config('loghub-client.queue.enabled'));
        $this->assertFalse(config('loghub-client.schedule.enabled'));

        $this->artisan('loghub:flush')
            ->expectsOutputToContain('Processed 0 records in 0 batches.')
            ->assertSuccessful();
    }

    public function test_command_optionally_dispatches_a_bounded_queue_job(): void
    {
        Queue::fake();
        config()->set('loghub-client.queue.enabled', true);
        config()->set('loghub-client.queue.connection', 'redis');
        config()->set('loghub-client.queue.name', 'loghub');

        $this->artisan('loghub:flush --max-batches=3')
            ->expectsOutputToContain('LogHub spool flush queued.')
            ->assertSuccessful();

        Queue::assertPushed(FlushLogHubSpool::class, function (FlushLogHubSpool $job): bool {
            return $job->maximumBatches === 3
                && $job->connection === 'redis'
                && $job->queue === 'loghub';
        });
    }

    public function test_minutely_schedule_is_overlap_protected_and_opt_in(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'loghub:flush'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertFalse($event->filtersPass($this->app));

        config()->set('loghub-client.schedule.enabled', true);

        $this->assertTrue($event->filtersPass($this->app));
    }
}
