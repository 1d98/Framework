# ETag middleware (RFC 7232)

What this is: conditional `GET` / `If-None-Match` → `304 Not Modified` short-circuit, plus optional `If-Match` → `412 Precondition Failed` for optimistic concurrency. Reduces bandwidth on repeat reads, prevents concurrent-update data loss on writes.

## Why this exists

Every `GET /users/42` today returns a 200 with the full body, even if the client (or its CDN) saw the same representation one second ago. Wasted bandwidth, wasted CPU on the server. The standard fix is a strong or weak `ETag` and an `If-None-Match` round-trip — every CDN supports it, every HTTP client supports it, the framework should emit one.

## Usage

```php
use Framework\Http\Middleware\EtagMiddleware;
use Framework\Http\Middleware\EtagPolicy;

$pipeline->pipe(new EtagMiddleware(
    policy: new EtagPolicy(
        algorithm: 'xxh128',          // or 'sha256' for collision-sensitive APIs
        weak: false,                 // strong etags by default
        // Per-route opt-out — skip etag for non-cacheable resources:
        skip: fn(Request $r) => str_starts_with($r->path, '/me/'),
        // Paths where If-Match is enforced (412 on miss):
        ifMatchPaths: ['/users/{id}', '/orders/{id}'],
    ),
));
```

A first `GET` against a cacheable path:

```
HTTP/1.1 200 OK
ETag: "a1b2c3d4e5f6..."
Cache-Control: private, max-age=0, must-revalidate
```

A repeat with `If-None-Match`:

```
GET /users/42 HTTP/1.1
If-None-Match: "a1b2c3d4e5f6..."
```

→ `304 Not Modified` with the same `ETag` header and an empty body.

## Strong vs weak

Strong (default) is byte-exact: two `ETag: "abc"` values match only if the bodies are byte-identical. Weak (`W/"abc"`) is semantic-equivalent: two responses that render the same HTML but with different whitespace match. Use strong by default; switch to weak only when the response is dynamic and you cannot guarantee byte stability across renders.

## Algorithm allowlist

`EtagPolicy` ([`src/Http/Middleware/EtagPolicy.php:44`](../../src/Http/Middleware/EtagPolicy.php)) restricts `algorithm` to a fixed `ALLOWED_ALGORITHMS = ['xxh128', 'sha256']` list. Passing any other `hash_algos()` value — `md5`, `sha1`, `crc32`, `fnv1a64`, etc. — throws `InvalidArgumentException` at boot. The two remaining algorithms are stable across PHP 8.5 versions and platforms (xxHash is shipped with PHP 8.1+ as a first-class `hash_algos()` entry).

Pick on cost vs collision-sensitivity:

| Algorithm | Speed | When to use |
|---|---|---|
| `xxh128` (default) | ~12 GB/s | Cache validation. 128 bits is well past the collision floor for HTTP cache validation. |
| `sha256` | ~400 MB/s | Idempotency tokens and other use cases where the etag is also used as a content-addressed handle. Slower but cryptographically strong. |

## Pipeline ordering

When the `CompressionMiddleware` is also active, it MUST run BEFORE `EtagMiddleware` so the etag reflects the on-the-wire body (gzipped), not the original. The same applies to any other body-transforming middleware (a future `HtmlMinifyMiddleware`, etc.).

```
pipe(new CompressionMiddleware(...));   // first
pipe(new EtagMiddleware(...));          // second
```

The `Vary` header from `CompressionMiddleware` is preserved; a `Vary: Accept-Encoding` response will have a different etag per encoding by virtue of the body bytes being different.

## If-Match (optimistic concurrency)

`If-Match` is a write-side primitive: the client says "I have resource version X; only apply my update if you still have X". When the server's etag differs, the update is rejected with `412 Precondition Failed` — the client re-fetches the resource and re-tries.

`If-Match` is off by default because it is a footgun if wired without thought (a misconfigured client that forgets to send the header will see all writes silently pass). Opt in per route via `EtagPolicy::$ifMatchPaths`:

```php
new EtagPolicy(ifMatchPaths: ['/users/{id}', '/orders/{id}'])
```

For the listed paths, the middleware enforces `If-Match` and throws `PreconditionFailedHttpException` (412) on a miss. The exception carries the standard `ETag` header so the client can re-fetch and compare.

## What this is NOT

- **Not a cache.** The etag is computed on every response; the framework does not store the body. A CDN (Varnish, CloudFront) is the right place to cache.
- **Not `Last-Modified` / `If-Modified-Since`.** ETag is the more expressive primitive; the framework ships only ETag. Adding `Last-Modified` would require either a timestamp source (the `Validator`'s `createdAt` on the entity?) or a per-route hook.
- **Not range-request support.** `Range: bytes=0-1023` is a separate feature (byte-range serving) that the framework does not currently ship. If you need it, the etag's hash makes a fine `If-Range` value.
