<?php

namespace Ojcloveu\LogHub\Spool;

final readonly class SpoolBatch
{
    /**
     * @param  list<array<string, mixed>>  $records
     * @param  list<string>  $remainingLines
     */
    public function __construct(
        public string $id,
        public string $leasedPath,
        public array $records,
        public array $remainingLines,
        public int $attempts,
    ) {}
}
