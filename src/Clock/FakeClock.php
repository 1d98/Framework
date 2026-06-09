<?php

declare(strict_types=1);

namespace Framework\Clock;

/**
 * Test double for {@see Clock}: holds an explicit `now()` value
 * and lets the test advance time in microseconds. Used by
 * `RateLimitMiddlewareTest` to assert bucket refill without
 * `usleep()`.
 */
final class FakeClock implements Clock
{
    private float $now;

    public function __construct(float $start = 0.0)
    {
        $this->now = $start;
    }

    public function now(): float
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }

    public function set(float $now): void
    {
        $this->now = $now;
    }
}
