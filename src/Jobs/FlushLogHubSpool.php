<?php

namespace Ojcloveu\LogHub\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Ojcloveu\LogHub\Delivery\SpoolDrain;

final class FlushLogHubSpool implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public readonly int $maximumBatches)
    {
        $connection = config('loghub-client.queue.connection');

        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }

        $this->onQueue((string) config('loghub-client.queue.name', 'default'));
    }

    public function handle(SpoolDrain $drain): void
    {
        $drain->drain($this->maximumBatches);
    }
}
