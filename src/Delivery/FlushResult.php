<?php

namespace Ojcloveu\LogHub\Delivery;

final readonly class FlushResult
{
    public function __construct(
        public int $records,
        public bool $delivered,
    ) {}
}
