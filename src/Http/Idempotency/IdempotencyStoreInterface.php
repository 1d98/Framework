<?php

declare(strict_types=1);

namespace Framework\Http\Idempotency;

use Framework\Http\Cookie\Cookie;

/**
 * Persistence contract for {@see \Framework\Http\Middleware\IdempotencyKeyMiddleware}.
 *
 * The middleware is storage-agnostic — production deployments
 * back this with Redis / Memcached / a database, the skeleton
 * ships an in-memory implementation (per-process) and a
 * filesystem implementation (per-host, atomic-rename).
 *
 * **Race semantics.** `tryReserve` is the only method that
 * changes state on a "request in flight" call; it MUST be
 * atomic across processes on the same host (the filesystem
 * adapter does this with `flock(LOCK_NB)`, the in-memory
 * adapter does it with the PHP process model). When
 * `tryReserve` returns `false`, a concurrent request holds
 * the slot — the middleware returns `409 Conflict`.
 *
 * **Cross-instance warning.** The in-memory adapter is
 * per-process (PHP-FPM worker, Octane worker, etc.). Behind
 * a load balancer, two parallel workers can each `tryReserve`
 * the same key and both succeed — the first to `put` wins,
 * the second's `put` overwrites. For multi-instance
 * deployments, use a shared store (Redis `SETNX` + TTL).
 */
interface IdempotencyStoreInterface
{
    /**
     * Look up a previously-stored entry. Returns `null` when
     * no entry exists, or when the existing entry's `method`,
     * `path`, or `bodyHash` does not match. Implementations
     * MUST throw {@see IdempotencyConflictException} when an
     * entry exists but the request shape differs — the
     * middleware relies on this to convert the conflict
     * into a 422.
     *
     * @throws IdempotencyConflictException Same key, different
     *     request (method, path, or body).
     */
    public function get(string $key, string $method, string $path, string $bodyHash): ?IdempotencyEntry;

    /**
     * Persist the response for a successful first request.
     * Overwrites any existing entry for the same key (the
     * caller has already won the {@see self::tryReserve}
     * race, so the in-flight slot is theirs).
     */
    public function put(
        string $key,
        string $method,
        string $path,
        string $bodyHash,
        IdempotencyEntry $entry,
    ): void;

    /**
     * Atomic "I'm about to process this key" claim. Returns
     * `true` when the caller wins the race (and therefore
     * must run the handler), `false` when a concurrent
     * request is already in flight.
     */
    public function tryReserve(string $key, string $method, string $path, string $bodyHash): bool;

    /**
     * Drop entries older than `$olderThanSeconds`. Returns
     * the number of entries dropped (for logging / tests).
     * The middleware calls this opportunistically; the
     * implementation may be a no-op for stores that already
     * enforce TTL at the storage layer (Redis `EX`,
     * filesystem mtime + startup sweep).
     */
    public function sweep(int $olderThanSeconds): int;

    /**
     * Drop a single entry by its `Idempotency-Key`. Called by
     * {@see \Framework\Http\Middleware\IdempotencyKeyMiddleware}
     * when the handler produced a response shape that cannot
     * be cached for replay (currently a `StreamedResponse`,
     * and any future `ResponseInterface` implementor the
     * middleware does not know how to serialise). Releasing
     * the reservation immediately lets the next request with
     * the same key re-execute instead of being rejected by
     * the held reservation in {@see self::tryReserve()}.
     *
     * **Idempotent.** Safe to call when no entry exists for
     * `$key` (a no-op, no exception). The implementation
     * MUST NOT throw on a missing entry.
     */
    public function forget(string $key): void;
}
