<?php

declare(strict_types=1);

namespace Framework\Http\Middleware;

use Closure;
use Framework\Http\Request\Request;

/**
 * Per-route policy for {@see EtagMiddleware}.
 *
 * The middleware installs an `ETag` header on every cacheable
 * response by default. Operators opt in / out per route via this
 * policy:
 *
 *  - `algorithm`: hash function for the etag value. Default
 *    `xxh128` (fast, 128-bit, non-cryptographic — fine for
 *    general-purpose cache validation). `sha256` for
 *    collision-sensitive APIs (idempotency tokens, etc.).
 *    The constructor restricts the algorithm to a fixed
 *    allowlist (`xxh128`, `sha256`) — narrower than what
 *    `hash_algos()` would permit — to avoid ever emitting
 *    etags derived from broken or platform-specific hashes.
 *  - `weak`: emit a `W/`-prefixed etag (semantic-equivalent
 *    match). Default `false` (strong, byte-exact).
 *  - `skip`: closure that decides per-request whether to skip
 *    etag generation. Default `null` (never skip).
 *  - `ifMatchPaths`: routes for which the middleware enforces
 *    the `If-Match` precondition (412 on miss). Off by default
 *    for safety — `If-Match` is optimistic concurrency and is
 *    a footgun if wired without thought.
 */
final readonly class EtagPolicy
{
    /**
     * Whitelisted hash algorithms for etag generation. Narrower
     * than `hash_algos()` on purpose — we want every emitted
     * etag to be derived from a hash that is known-stable across
     * PHP versions and platforms.
     *
     * @var list<string>
     */
    public const array ALLOWED_ALGORITHMS = ['xxh128', 'sha256'];

    /**
     * @param list<string> $ifMatchPaths
     */
    public function __construct(
        public string $algorithm = 'xxh128',
        public bool $weak = false,
        public ?Closure $skip = null,
        public array $ifMatchPaths = [],
    ) {
        if (!in_array($algorithm, self::ALLOWED_ALGORITHMS, true)) {
            throw new \InvalidArgumentException(
                "EtagPolicy: unsupported algorithm '{$algorithm}' "
                . "(allowed: " . implode(', ', self::ALLOWED_ALGORITHMS) . ')',
            );
        }
    }
}
