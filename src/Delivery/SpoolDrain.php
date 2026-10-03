<?php

namespace Ojcloveu\LogHub\Delivery;

final readonly class SpoolDrain
{
    public function __construct(private SpoolFlusher $flusher) {}

    public function drain(int $maximumBatches): DrainResult
    {
        $batches = 0;
        $records = 0;

        for ($index = 0; $index < max(1, $maximumBatches); $index++) {
            $result = $this->flusher->flush();

            if ($result->records === 0) {
                return new DrainResult($batches, $records, true);
            }

            $batches++;
            $records += $result->records;

            if (! $result->delivered) {
                return new DrainResult($batches, $records, false);
            }
        }

        return new DrainResult($batches, $records, false);
    }
}
