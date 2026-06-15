<?php

declare(strict_types=1);

namespace Framework\Http\Middleware;

use Framework\Http\Request\Request;

/**
 * Per-route rate-limit policy.
 *
 * A {@see RateLimitMiddleware} is configured with default `capacity`
 * / `refillPerSecond` / `scope` values; when an application needs
 * a stricter limit on `/login` and a looser limit on `/api/`, the
 * caller passes a `policyResolver` closure that maps the matched
 * route path to a `RateLimitPolicy`. The middleware then uses the
 * per-policy bucket (keyed `<ip>:<scope>`) and emits the policy's
 * `scope` as the `X-RateLimit-Scope` response header.
 *
 * `scope` is also the per-bucket namespace — two policies with the
 * same `scope` share a bucket (which is what you want when two
 * routes are in the same trust group), two policies with
 * different `scope` have independent buckets. The defaults
 * (`scope: 'default'`) means the bucket is the bare IP, matching
 * the legacy behavior.
 */
final readonly class RateLimitPolicy
{
    public function __construct(
        public int $capacity,
        public float $refillPerSecond,
        public string $scope = 'default',
    ) {
    }
}
