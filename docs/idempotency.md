# Idempotency-Key middleware

What this is: Stripe-style safe-POST replay. The first request with a given `Idempotency-Key` runs the handler; retries within the TTL window replay the captured response verbatim (same status, body, headers, `Set-Cookie`). Network blips that cause client retries can no longer apply a payment, an order, or an account creation twice.

## Why this exists

Network blips cause clients to retry POSTs. Without server-side dedup, a single user action can be applied twice — a duplicate payment, a duplicate order, a duplicate account creation. The `Idempotency-Key` header is the standard defence: the client picks a key, the server caches the first response under that key, retries replay the cached response.

## Usage

```php
use Framework\Http\Middleware\IdempotencyKeyMiddleware;
use Framework\Http\Idempotency\InMemoryIdempotencyStore;
use Framework\Http\Idempotency\FilesystemIdempotencyStore;

$pipeline->pipe(new IdempotencyKeyMiddleware(
    store: new FilesystemIdempotencyStore(
        directory: __DIR__ . '/../var/idempotency',
        // ttl defaults to 86_400 seconds (24 hours)
    ),
    methods: ['POST', 'PUT', 'PATCH', 'DELETE'],
    requiredOn: ['POST', 'PUT'],  // 400 if missing on these
));
```

The default store is the in-memory one. For multi-instance deployments, write a Redis / Memcached adapter (the `IdempotencyStoreInterface` is 4 methods) and inject it via the container.

## Wire semantics

The middleware distinguishes four cases for an `Idempotency-Key` request:

| Case | Response |
|---|---|
| First request with key K | Handler runs, response stored for 24h |
| Retry within TTL, same `method` + `path` + `body` | Cached response replayed verbatim + `Idempotency-Replayed: true` header |
| Same key, different body / method / path | `422 Unprocessable Entity` (RFC 7231 §4.2.2: an Idempotency-Key identifies a single logical request) |
| Same key, request currently in flight on another worker | `409 Conflict` (only the in-flight request can write the entry — replaying "the cached entry" would be lying) |
| Key missing on a method in `requiredOn` | `400 Bad Request` |

The `Idempotency-Replayed: true` header is the only signal a client gets that the response is a replay — useful for instrumentation and debugging.

## Body-hash mismatch

A retry that reuses the same key with a different body is rejected with `422`. The contract: pick a fresh `Idempotency-Key` if the request shape changed. Never silently overwrite an existing entry with a different body — that would let a buggy client "update" a payment by sending a different amount under the same key.

## Cross-instance scope

The `InMemoryIdempotencyStore` is per-process (PHP-FPM worker, Octane worker, etc.). Behind a load balancer, two parallel workers each maintain an independent copy of the same key's slot. The `FilesystemIdempotencyStore` is per-host (atomic-rename + `flock(LOCK_NB)`).

For multi-instance deployments, write a Redis / Memcached adapter. The interface is small:

```php
interface IdempotencyStoreInterface
{
    public function get(string $key, string $method, string $path, string $bodyHash): ?IdempotencyEntry;
    public function put(string $key, string $method, string $path, string $bodyHash, IdempotencyEntry $entry): void;
    public function tryReserve(string $key, string $method, string $path, string $bodyHash): bool;
    public function sweep(int $olderThanSeconds): int;
    public function forget(string $key): void;
}
```

The 5th method, `forget()`, is the release valve for streamed responses — see [the next section](#streamed-responses).

## Streamed responses

A `StreamedResponse` body is produced at `send()` time by an emitter closure — there is no string to capture into an `IdempotencyEntry`. The middleware therefore cannot replay a streamed response on retry, and must not pretend otherwise.

Since 0.7.0 the middleware's flow on a streamed response is:

1. `tryReserve()` claims the slot — same as for a buffered response.
2. `next($request)` invokes the handler, which returns a `StreamedResponse`.
3. The middleware detects the non-`Response` return type, calls `IdempotencyStoreInterface::forget($key)` to release the reservation, and returns the response unchanged.

The next request with the same `Idempotency-Key` is free to re-execute the handler instead of hitting `tryReserve() → false` and getting `409 Conflict`. Pre-0.7.0, the held reservation stayed for the full TTL window and every retry got a 409 long after the original stream had completed — see the 0.7.0 changelog entry for the full bug write-up.

### What this means for clients

- **No replay guarantee for streams.** A retry is a re-execution, not a replay. The response is fresh; the side effect (a token emitted, a record written, a row updated) is performed again.
- **Generate a fresh `Idempotency-Key` per attempt** if you want at-most-once semantics for the underlying side effect. The framework cannot deduplicate streaming responses — it cannot store what it never materialises.
- **Buffered responses are unaffected.** A handler that returns a regular `Response` (the default) still gets the replay-on-retry semantics from the rest of this document. The streamed-response opt-out is only triggered when the handler's return type is not `Response`.

> **Common pitfall.** Building a streaming endpoint with an `Idempotency-Key` header on every retry will appear to "work" in development (one retry, no side effect) and fail under load (the broker re-executes because the response was forgotten). If at-most-once is the requirement, the side effect must be made idempotent at the application layer (a unique constraint on the database row, a payment-intent dedup key on the payment gateway) — `Idempotency-Key` cannot enforce it for streams.

A Redis adapter: `SETNX` with TTL for `tryReserve` (returns false on collision), `GET` for `get` (decode JSON), `SET` with TTL for `put`. 30 lines of code.

## What this is NOT

- **Not a job queue.** A job queue persists the request until the worker is free; this middleware persists the response after the worker finishes. The retry's job is to read the cached response, not to wait for the worker.
- **Not a session.** The cached entry is keyed on `Idempotency-Key`, not on the authenticated user.
- **Not a deduplication of all writes.** Only requests that *carry* an `Idempotency-Key` participate. GETs are excluded (they are already idempotent by HTTP spec); PATCH and DELETE are optional (configurable via `methods` / `requiredOn`).
- **Not a substitute for application-level idempotency.** A payment API should also write its own `payment_id` and dedupe at the database layer; `Idempotency-Key` is a transport-layer defence, not a data-layer one.
