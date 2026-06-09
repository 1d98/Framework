<?php

declare(strict_types=1);

namespace Framework\Clock;

/**
 * Minimal time source used by components that need a deterministic
 * "now" for testing or for swapping in a fake clock in long-running
 * processes.
 *
 * Mirrors the surface of the standard {@see \Psr\Clock\ClockInterface}
 * but ships in-tree to keep the framework zero-dependency. A
 * production caller can pass `SystemClock` (real wall-clock) and a
 * test can pass a closure-backed fake. Components MUST NOT call
 * `microtime(true)` directly when an injected `Clock` is available.
 */
interface Clock
{
    /**
     * Return the current time as a Unix timestamp with microsecond
     * precision. Same shape as `microtime(true)`.
     */
    public function now(): float;
}
