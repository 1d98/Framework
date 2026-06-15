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
     * Process-wide bucket store. Keyed by `<ip>:<scope>` (or
     * `<custom-key>:<scope>` when a `keyExtractor` is supplied).
     * Each entry holds the current token count, the timestamp of
     * the last refill, the policy's `capacity`, the policy's
     * `refillPerSecond` rate, and the policy's `scope` (echoed in
     * the `X-RateLimit-Scope` response header).
     *
     * The `array` shape is intentionally not-generic: a single
     * shape makes the storage trivial to swap to a different
     * backend without touching the algorithm.
     *
     * @var array<string, array{tokens: float, updated: float, capacity: int, refill: float, scope: string}>
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
     * @param ?Closure $policyResolver Optional
     *     `(Request, routePath): RateLimitPolicy` that picks a
     *     per-route policy. When set, the matched route path is
     *     read off `$request` (the kernel stores the matched
     *     path in the `routed_path` attribute when routing
     *     succeeded; when not, the resolver receives `$request->path`).
     *     The returned policy's `scope` is the per-bucket
     *     namespace and is echoed in the `X-RateLimit-Scope`
     *     response header. Default `null` — every request uses the
     *     constructor `$capacity` / `$refillPerSecond` and the
     *     `scope: 'default'` namespace (legacy behavior).
     * @param int $maxRetryAfter Maximum value (in seconds) the
     *     `Retry-After` header can take on a 429. Default 86400
     *     (1 day). The header is `ceil(seconds-to-next-token)` and
     *     is clamped to `[1, $maxRetryAfter]` to prevent leaking
     *     a "retry in 30 years" or "retry in 0 seconds" response
     *     to a misbehaving client.
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
        private ?Closure $policyResolver = null,
        private int $maxRetryAfter = 86400,
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
        if ($maxRetryAfter < 1) {
            throw new InvalidArgumentException('RateLimitMiddleware: maxRetryAfter must be >= 1');
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

        $policy = $this->resolvePolicy($request);
        $bucketKey = $key . ':' . $policy->scope;

        $throttleException = null;

        $response = $this->locked(function () use ($bucketKey, $now, $next, $request, $policy, &$throttleException): Response {
            $bucket = self::$buckets[$bucketKey] ?? [
                'tokens' => (float) $policy->capacity,
                'updated' => $now,
                'capacity' => $policy->capacity,
                'refill' => $policy->refillPerSecond,
                'scope' => $policy->scope,
            ];

            $elapsed = max(0.0, $now - $bucket['updated']);
            $bucket['tokens'] = min((float) $policy->capacity, $bucket['tokens'] + $elapsed * $policy->refillPerSecond);

            if ($bucket['tokens'] < 1.0) {
                self::$buckets[$bucketKey] = $bucket;
                $retryAfter = $this->computeRetryAfter($bucket['tokens'], $policy->refillPerSecond, $now);
                $throttleException = new TooManyRequestsHttpException(
                    'Rate limit exceeded',
                    retryAfter: $retryAfter,
                );
                throw $throttleException;
            }

            $bucket['tokens'] -= 1.0;
            $bucket['updated'] = $now;
            self::$buckets[$bucketKey] = $bucket;

            /** @var Response $response */
            $response = $next($request);
            return $this->applyRateLimitHeaders(
                response: $response,
                policy: $policy,
                tokensRemaining: $bucket['tokens'],
                now: $now,
                isThrottled: false,
                retryAfter: null,
            );
        });

        if ($throttleException !== null) {
            // The exception already carries `Retry-After` in its
            // `headers()`; the kernel's error renderer picks it
            // up via ProblemDetails::headers() and adds it to
            // the 429 response. `X-RateLimit-*` headers are
            // intentionally NOT added on the throttled path —
            // RFC 6585 / 7231 require `Retry-After`; the rest
            // are an informational convenience on success only.
            throw $throttleException;
        }

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
     * Resolve the per-route policy for a request. When a
     * `policyResolver` was supplied at construction time, the
     * matched route path is read off the request's `routed_path`
     * attribute (the kernel stores it there after a successful
     * match) and the closure is called. When no resolver is
     * configured, the default `RateLimitPolicy` (built from the
     * constructor's `$capacity` / `$refillPerSecond` / `scope:
     * 'default'`) is returned, matching the legacy behavior.
     */
    private function resolvePolicy(Request $request): RateLimitPolicy
    {
        if ($this->policyResolver === null) {
            return new RateLimitPolicy(
                capacity: $this->capacity,
                refillPerSecond: $this->refillPerSecond,
                scope: 'default',
            );
        }

        $routedPath = $request->getAttribute('routed_path');
        $routePath = is_string($routedPath) && $routedPath !== '' ? $routedPath : $request->path;

        $policy = ($this->policyResolver)($request, $routePath);
        if (!$policy instanceof RateLimitPolicy) {
            // A misbehaving resolver should never crash the request
            // — fall back to the defaults so the limit still applies.
            return new RateLimitPolicy(
                capacity: $this->capacity,
                refillPerSecond: $this->refillPerSecond,
                scope: 'default',
            );
        }
        return $policy;
    }

    /**
     * Compute the `Retry-After` (in seconds) for a 429 response.
     *
     * The bucket has `tokens` < 1.0 right now. To get to 1 token
     * (the next request that will be allowed), we need
     * `(1 - tokens)` more tokens; at `refill` tokens per second,
     * the wait is `(1 - tokens) / refill` seconds. `ceil()` so
     * the client never gets `Retry-After: 0` (which RFC 7231
     * treats as "retry immediately" and would just trigger the
     * 429 again). The result is clamped to `[1, $maxRetryAfter]`.
     */
    private function computeRetryAfter(float $tokens, float $refill, float $now): int
    {
        $missing = max(0.0, 1.0 - $tokens);
        $seconds = (int) ceil($missing / $refill);
        if ($seconds < 1) {
            $seconds = 1;
        }
        if ($seconds > $this->maxRetryAfter) {
            $seconds = $this->maxRetryAfter;
        }
        return $seconds;
    }

    /**
     * Compute the Unix timestamp at which the bucket is full again.
     * If the bucket has `tokens` right now and the policy refills
     * at `refill` tokens/second, the time to reach `capacity` is
     * `(capacity - tokens) / refill` seconds from `now`. The return
     * value is rounded up to the next whole second and matches the
     * de-facto convention used by GitHub, Twitter, etc. for the
     * `X-RateLimit-Reset` header.
     */
    private function computeResetEpoch(float $now, float $tokens, int $capacity, float $refill): int
    {
        if ($refill <= 0.0) {
            return (int) ceil($now);
        }
        $missing = max(0.0, (float) $capacity - $tokens);
        $seconds = (int) ceil($missing / $refill);
        return (int) ceil($now) + $seconds;
    }

    /**
     * Apply `X-RateLimit-Limit`, `X-RateLimit-Remaining`,
     * `X-RateLimit-Reset`, and `X-RateLimit-Scope` to the response.
     * On 429 (`isThrottled: true`), also add `Retry-After` with
     * the pre-computed value.
     */
    private function applyRateLimitHeaders(
        Response $response,
        RateLimitPolicy $policy,
        float $tokensRemaining,
        float $now,
        bool $isThrottled,
        ?int $retryAfter,
    ): Response {
        $response = $response->withHeader('X-RateLimit-Limit', (string) $policy->capacity);
        $response = $response->withHeader(
            'X-RateLimit-Remaining',
            (string) max(0, (int) floor($tokensRemaining)),
        );
        $response = $response->withHeader(
            'X-RateLimit-Reset',
            (string) $this->computeResetEpoch($now, $tokensRemaining, $policy->capacity, $policy->refillPerSecond),
        );
        $response = $response->withHeader('X-RateLimit-Scope', $policy->scope);
        if ($isThrottled && $retryAfter !== null) {
            $response = $response->withHeader('Retry-After', (string) $retryAfter);
        }
        return $response;
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
