<?php

declare(strict_types=1);

namespace Framework\Http\Middleware;

use Closure;
use Framework\Clock\Clock;
use Framework\Clock\SystemClock;
use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Exception\TooManyRequestsHttpException;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * In-memory token-bucket rate limiter.
 *
 * Each unique key gets its own bucket, refilled at
 * `$refillPerSecond` tokens per second, capped at `$capacity` tokens.
 * The bucket is decremented on every successful call; if a request
 * would push it below zero, the middleware throws
 * {@see TooManyRequestsHttpException} (HTTP 429) *before* the
 * downstream handler runs.
 *
 * **Not for production multi-instance deployments.** The bucket
 * state lives in a static `array<string, Bucket>` on the class — it
 * is per-process, not shared across PHP-FPM workers, Octane
 * workers, or hosts. Behind a load balancer, two parallel workers
 * each maintain an independent copy of the same client's bucket,
 * so a determined attacker can sustain `2 * capacity` requests per
 * window just by being routed round-robin. For production, back the
 * state with Redis / APCu / Memcached (a single-host shared store
 * is still a strict improvement over a per-process array). The
 * class is shipped as a reference implementation; the security
 * checklist in {@see \Framework\Security\CsrfMiddleware} applies
 * in spirit — audit the wiring before going live.
 *
 * Within a single PHP-FPM process, multiple sub-processes / Octane
 * workers can race the read-modify-write of `$buckets` and lose
 * tokens. Two mitigations are offered here:
 *
 *   1. **Optional `flock()` via `$lockPath`.** Pass a writable
 *      filesystem path (e.g. `sys_get_temp_dir() . '/fw-ratelimit.lock'`)
 *      and the read-modify-write will be wrapped in
 *      `flock(LOCK_EX)`. This serializes processes on the same
 *      host but does NOT help across hosts.
 *   2. **Bucket TTL + sweep** (`$bucketTtl`, `$sweepInterval`).
 *      Buckets whose `updated` is older than `$bucketTtl` seconds
 *      are dropped during the sweep, bounding memory growth from
 *      one-shot clients / scanners / unique `X-Forwarded-For`
 *      values. Sweep is amortized — runs at most once per
 *      `$sweepInterval` per process.
 *
 * Neither mitigation is a substitute for a shared store
 * (Redis/APCu) in a multi-instance deployment. The PHPDoc on
 * `$lockPath` documents the trade-off.
 *
 * Defaults: 60 tokens capacity, 1 token / second refill, 1 hour
 * bucket TTL, 60 s sweep interval, no file lock. A user opening
 * five tabs at once can spend the burst capacity; a sustained
 * flood is capped at the refill rate. Tune `$capacity` up for
 * bursty APIs, `$refillPerSecond` up for steady throughput.
 *
 * **Class shape — `final`, not `final readonly`.** A `readonly` class
 * forbids any writable property (instance or static), and the
 * bucket store is a writeable static `array` — see the same
 * exception carved out for `Route` in the project conventions.
 * Every other property on this class
 * is `readonly` on the constructor signature, preserving the
 * value-object immutability surface; the class-level modifier is
 * relaxed only so the static store can be filled in place.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    private const string UNKNOWN_KEY = 'unknown';

    /**
     * Process-wide bucket store. Keyed by the resolved request key;
     * each entry holds the current token count and the timestamp of
     * the last refill. The `array` shape is intentionally
     * not-generic: a single shape (`['tokens' => float, 'updated'
     * => float]`) makes the storage trivial to swap to a different
     * backend without touching the algorithm.
     *
     * @var array<string, array{tokens: float, updated: float}>
     */
    private static array $buckets = [];

    /**
     * Timestamp of the last GC sweep, in seconds since epoch
     * (whatever the injected Clock reports). Used to amortize the
     * cost of {@see self::sweep()} — the sweep runs at most once
     * per `$sweepInterval` per process.
     *
     * A separate `static bool $hasSwept` flag is required because
     * `FakeClock(0.0)` (and any clock that happens to start at
     * `0.0`) would otherwise leave `$lastSweepAt` at `0.0` after
     * the first sweep, and the `lastSweepAt === 0.0` "never
     * swept" check would re-fire on every request. The boolean
     * tracks "has the initial sweep ever run" independently of
     * the time.
     */
    private static float $lastSweepAt = 0.0;

    private static bool $hasSwept = false;

    private Clock $clock;

    /**
     * @param int $capacity Maximum tokens in a bucket. Must be >= 1.
     *     The first request after a long idle period is always
     *     allowed because the bucket is refilled to `$capacity`
     *     before the check.
     * @param float $refillPerSecond Tokens added per second of idle
     *     time. Must be > 0. A `$refillPerSecond` of 0.5 means one
     *     new token every two seconds.
     * @param ?Clock $clock Time source. Defaults to a fresh
     *     `SystemClock` (real wall-clock). Tests pass a fake.
     * @param ?Closure $keyExtractor Optional `(Request): string`
     *     that derives the per-bucket key from the request. Defaults
     *     to `$request->ip() ?? self::UNKNOWN_KEY`. Pass a custom
     *     extractor to key on a session id, an authenticated user
     *     id, an API token, or `(ip, route)` — the framework does
     *     not ship these defaults because each requires a
     *     trust list / auth context the framework does not own.
     * @param bool $allowMissingKey When the default key extractor
     *     cannot resolve a key (e.g. `Request::ip()` returned null
     *     because the request has no `REMOTE_ADDR`) and no custom
     *     `$keyExtractor` was supplied, fall back to the shared
     *     `UNKNOWN_KEY` bucket. Default `true` matches the legacy
     *     behavior. Set to `false` to refuse such requests with
     *     `TooManyRequestsHttpException` instead — recommended for
     *     public-facing apps where an unauthenticated anonymous
     *     flood is the threat model.
     * @param int $bucketTtl Seconds of inactivity after which a
     *     bucket is eligible for GC. Default 3600 (1 hour). A bucket
     *     that is hit more often than this never expires. Set to
     *     `PHP_INT_MAX` to disable GC (legacy behavior, but allows
     *     unbounded memory growth).
     * @param int $sweepInterval Seconds between GC sweeps in this
     *     process. Default 60. The sweep itself is O(N) over the
     *     bucket count, but only runs at this interval.
     * @param ?string $lockPath Filesystem path to a lock file used
     *     to serialize the read-modify-write of `$buckets` across
     *     processes on the same host via `flock(LOCK_EX)`. Default
     *     `null` — no locking. Pass a path on a local filesystem
     *     (NFS may not honor `flock`); the file does not need to
     *     exist. Does NOT help across hosts — for that, use a
     *     shared store.
     */
    public function __construct(
        private int $capacity = 60,
        private float $refillPerSecond = 1.0,
        ?Clock $clock = null,
        private ?Closure $keyExtractor = null,
        private bool $allowMissingKey = true,
        private int $bucketTtl = 3600,
        private int $sweepInterval = 60,
        private ?string $lockPath = null,
    ) {
        if ($capacity < 1) {
            throw new InvalidArgumentException('RateLimitMiddleware: capacity must be >= 1');
        }
        if ($refillPerSecond <= 0.0) {
            throw new InvalidArgumentException('RateLimitMiddleware: refillPerSecond must be > 0');
        }
        if ($bucketTtl < 1) {
            throw new InvalidArgumentException('RateLimitMiddleware: bucketTtl must be >= 1 (use PHP_INT_MAX to disable GC)');
        }
        if ($sweepInterval < 1) {
            throw new InvalidArgumentException('RateLimitMiddleware: sweepInterval must be >= 1');
        }
        $this->clock = $clock ?? new SystemClock();
    }

    public function process(Request $request, callable $next): Response
    {
        $now = $this->clock->now();
        $this->maybeSweep($now);

        $key = $this->resolveKey($request);
        if ($key === null) {
            throw new BadRequestHttpException(
                'Rate limit requires a key (IP or custom extractor); no key could be resolved from this request',
            );
        }

        /** @var Response $response */
        $response = $this->locked(function () use ($key, $now, $next, $request): Response {
            $bucket = self::$buckets[$key] ?? ['tokens' => (float) $this->capacity, 'updated' => $now];

            $elapsed = max(0.0, $now - $bucket['updated']);
            $bucket['tokens'] = min((float) $this->capacity, $bucket['tokens'] + $elapsed * $this->refillPerSecond);

            if ($bucket['tokens'] < 1.0) {
                self::$buckets[$key] = $bucket;
                throw new TooManyRequestsHttpException('Rate limit exceeded');
            }

            $bucket['tokens'] -= 1.0;
            $bucket['updated'] = $now;
            self::$buckets[$key] = $bucket;

            /** @var Response $response */
            $response = $next($request);
            return $response;
        });

        return $response;
    }

    /**
     * Drop buckets that haven't been touched in `$bucketTtl`
     * seconds. Called from {@see self::process()} at most once
     * per `$sweepInterval`. Safe to call externally to force a
     * sweep (e.g. in long-running worker tests).
     */
    public function sweep(): int
    {
        if ($this->bucketTtl >= PHP_INT_MAX) {
            return 0;
        }
        $now = $this->clock->now();
        return $this->locked(function () use ($now): int {
            $cutoff = $now - $this->bucketTtl;
            $before = count(self::$buckets);
            self::$buckets = array_filter(
                self::$buckets,
                static fn(array $b): bool => $b['updated'] >= $cutoff,
            );
            self::$lastSweepAt = $now;
            self::$hasSwept = true;
            return $before - count(self::$buckets);
        });
    }

    private function maybeSweep(float $now): void
    {
        if ($this->sweepInterval >= PHP_INT_MAX || $this->bucketTtl >= PHP_INT_MAX) {
            return;
        }
        if (!self::$hasSwept || ($now - self::$lastSweepAt) >= $this->sweepInterval) {
            $this->locked(function () use ($now): void {
                $cutoff = $now - $this->bucketTtl;
                self::$buckets = array_filter(
                    self::$buckets,
                    static fn(array $b): bool => $b['updated'] >= $cutoff,
                );
                self::$lastSweepAt = $now;
                self::$hasSwept = true;
            });
        }
    }

    private function resolveKey(Request $request): ?string
    {
        if ($this->keyExtractor !== null) {
            $extracted = ($this->keyExtractor)($request);
            if (!is_string($extracted) || $extracted === '') {
                return $this->allowMissingKey ? self::UNKNOWN_KEY : null;
            }
            return $extracted;
        }
        $ip = $request->ip();
        if ($ip !== null) {
            return $ip;
        }
        return $this->allowMissingKey ? self::UNKNOWN_KEY : null;
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function locked(callable $fn): mixed
    {
        if ($this->lockPath === null) {
            return $fn();
        }
        $handle = @fopen($this->lockPath, 'c');
        if ($handle === false) {
            throw new RuntimeException("RateLimitMiddleware: cannot open lock file {$this->lockPath}");
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException("RateLimitMiddleware: cannot acquire lock on {$this->lockPath}");
            }
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
