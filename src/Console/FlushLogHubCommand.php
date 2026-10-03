<?php

namespace Ojcloveu\LogHub\Console;

use Illuminate\Console\Command;
use Ojcloveu\LogHub\Delivery\SpoolDrain;
use Ojcloveu\LogHub\Jobs\FlushLogHubSpool;

final class FlushLogHubCommand extends Command
{
    protected $signature = 'loghub:flush {--max-batches= : Maximum batches to process}';

    protected $description = 'Flush locally spooled logs to LogHub';

    public function handle(SpoolDrain $drain): int
    {
        $maximumBatches = max(1, (int) ($this->option('max-batches')
            ?: config('loghub-client.delivery.max_batches_per_run')));

        if (config('loghub-client.queue.enabled')) {
            FlushLogHubSpool::dispatch($maximumBatches);
            $this->components->info('LogHub spool flush queued.');

            return self::SUCCESS;
        }

        $result = $drain->drain($maximumBatches);
        $this->components->info(sprintf(
            'Processed %d records in %d batches.',
            $result->records,
            $result->batches,
        ));

        return $result->complete ? self::SUCCESS : self::FAILURE;
    }
}
