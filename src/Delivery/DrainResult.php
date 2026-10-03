<?php

namespace Ojcloveu\LogHub\Delivery;

final readonly class DrainResult
{
    public function __construct(
        public int $batches,
        public int $records,
        public bool $complete,
    ) {}
}
