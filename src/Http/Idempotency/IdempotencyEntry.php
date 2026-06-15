<?php

declare(strict_types=1);

namespace Framework\Http\Idempotency;

use Framework\Http\Cookie\Cookie;

/**
 * Stored response snapshot for a previously-completed idempotent
 * request. The wire form is preserved verbatim so a replay on
 * retry is byte-identical to the original (same status, same
 * body, same headers, same `Set-Cookie`).
 *
 * The hash chain (method + path + bodyHash) is keyed outside
 * the entry — the entry only carries the round-tripped data.
 * That separation lets the store implementer add their own
 * metadata (timestamp, request count, etc.) without
 * complicating the snapshot.
 */
final readonly class IdempotencyEntry
{
    /**
     * @param array<string, string> $headers All response headers
     *     EXCEPT `Set-Cookie` (cookies are in `$cookies`).
     * @param list<Cookie> $cookies Cookies to re-emit on replay
     *     (the original request may have set authentication
     *     cookies, and the retry must observe them too).
     * @param int $createdAt Unix timestamp of the first response
     *     capture; used by the store for TTL-based GC.
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers,
        public array $cookies,
        public int $createdAt,
    ) {
    }
}
