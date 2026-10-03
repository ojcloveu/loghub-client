<?php

namespace Ojcloveu\LogHub\Delivery;

use Illuminate\Http\Client\Factory;
use Ojcloveu\LogHub\Spool\SpoolWriter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class SpoolFlusher
{
    public function __construct(
        private SpoolWriter $spool,
        private Factory $http,
        private string $endpoint,
        private ?string $apiKey,
        private int $batchSize,
        private float $connectTimeout,
        private float $timeout,
        private int $maximumRetrySeconds,
    ) {}

    public function flush(): FlushResult
    {
        $batch = $this->spool->leaseBatch(max(1, $this->batchSize));

        if ($batch === null) {
            return new FlushResult(0, true);
        }

        try {
            if ($this->apiKey === null || $this->apiKey === '') {
                $this->spool->release($batch, $this->maximumRetrySeconds);

                return new FlushResult(count($batch->records), false);
            }

            $response = $this->http
                ->connectTimeout(max(0.1, $this->connectTimeout))
                ->timeout(max(0.1, $this->timeout))
                ->acceptJson()
                ->withToken($this->apiKey)
                ->post(rtrim($this->endpoint, '/').'/api/v1/logs/batch', [
                    'logs' => $batch->records,
                ]);

            if ($response->status() !== Response::HTTP_ACCEPTED) {
                $this->spool->release($batch, $this->maximumRetrySeconds);

                return new FlushResult(count($batch->records), false);
            }

            $this->spool->acknowledge($batch);

            return new FlushResult(count($batch->records), true);
        } catch (Throwable) {
            $this->spool->release($batch, $this->maximumRetrySeconds);

            return new FlushResult(count($batch->records), false);
        }
    }
}
