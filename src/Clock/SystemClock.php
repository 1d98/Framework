<?php

declare(strict_types=1);

namespace Framework\Clock;

/**
 * Default {@see Clock} implementation: returns `microtime(true)`.
 *
 * Used by production wiring; tests should pass a fake clock.
 */
final readonly class SystemClock implements Clock
{
    public function now(): float
    {
        return microtime(true);
    }
}
