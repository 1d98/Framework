<?php

declare(strict_types=1);

namespace Framework\Container;

use Closure;

interface ContainerInterface
{
    public function get(string $id): mixed;

    public function has(string $id): bool;

    /**
     * @param Closure(ContainerInterface): mixed $factory
     */
    public function set(string $id, Closure $factory): void;

    /**
     * @param class-string|Closure(ContainerInterface): mixed $concrete
     */
    public function bind(string $abstract, Closure|string $concrete): void;

    /**
     * Drop a single resolved singleton so the next get() re-resolves it.
     * Bindings and factories are configuration and remain untouched.
     */
    public function forget(string $id): void;

    /**
     * Clear all resolved singletons and the cycle-guard map.
     * Bindings and factories are configuration and remain untouched.
     */
    public function reset(): void;

    /**
     * Drop this instance's resolved singletons and the cycle-guard map.
     * Bindings and factories (the *configuration* of the container) are
     * preserved. This is an alias for `reset()`: it touches only the
     * per-instance state and deliberately does NOT clear the
     * process-wide static memoization caches — see
     * `wipeGlobalCaches()` for that.
     */
    public function wipe(): void;

    /**
     * Drop the process-wide memoization owned by the container's
     * implementation (`$typeExistsCache`, `$reflectionCache`) and
     * delegate to `Validator::clearCaches()` if the validator is
     * loaded. Affects every `Container` instance in the process; the
     * next `get()` on any of them re-runs `class_exists` and rebuilds
     * `ReflectionClass` for every FQCN ever resolved. Use deliberately.
     */
    public static function wipeGlobalCaches(): void;
}
