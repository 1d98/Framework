<?php

declare(strict_types=1);

namespace Framework\Http\Middleware;

use Closure;
use Framework\Clock\Clock;
use Framework\Clock\SystemClock;
use Framework\Http\Exception\TooManyRequestsHttpException;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use InvalidArgumentException;

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
 * Defaults: 60 tokens capacity, 1 token / second refill. A user
 * opening five tabs at once can spend the burst capacity; a
 * sustained flood is capped at the refill rate. Tune `$capacity`
 * up for bursty APIs, `$refillPerSecond` up for steady throughput.
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
     */
    public function __construct(
        private int $capacity = 60,
        private float $refillPerSecond = 1.0,
        ?Clock $clock = null,
        private ?Closure $keyExtractor = null,
    ) {
        if ($capacity < 1) {
            throw new InvalidArgumentException('RateLimitMiddleware: capacity must be >= 1');
        }
        if ($refillPerSecond <= 0.0) {
            throw new InvalidArgumentException('RateLimitMiddleware: refillPerSecond must be > 0');
        }
        $this->clock = $clock ?? new SystemClock();
    }

    public function process(Request $request, callable $next): Response
    {
        $key = $this->resolveKey($request);
        $bucket = self::$buckets[$key] ?? ['tokens' => (float) $this->capacity, 'updated' => $this->clock->now()];

        $now = $this->clock->now();
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
    }

    private function resolveKey(Request $request): string
    {
        if ($this->keyExtractor !== null) {
            $extracted = ($this->keyExtractor)($request);
            if (!is_string($extracted) || $extracted === '') {
                return self::UNKNOWN_KEY;
            }
            return $extracted;
        }
        $ip = $request->ip();
        return $ip ?? self::UNKNOWN_KEY;
    }
}
