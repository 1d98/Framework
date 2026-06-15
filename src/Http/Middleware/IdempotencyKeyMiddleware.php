<?php

declare(strict_types=1);

namespace Framework\Http\Middleware;

use Framework\Http\Cookie\Cookie;
use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Exception\ConflictHttpException;
use Framework\Http\Idempotency\IdempotencyConflictException;
use Framework\Http\Idempotency\IdempotencyStoreInterface;
use Framework\Http\Idempotency\InMemoryIdempotencyStore;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use InvalidArgumentException;

/**
 * Stripe-style `Idempotency-Key` middleware. Safe-POST replay:
 * the first request with a given key runs the handler; retries
 * within the TTL window replay the captured response verbatim
 * (same status, same body, same headers, same `Set-Cookie`).
 *
 * **Threat model.** Network blips cause clients to retry POSTs.
 * Without server-side dedup, a single user action can be applied
 * twice — a duplicate payment, a duplicate order, a duplicate
 * account. The `Idempotency-Key` header is the standard
 * defence: the client picks a key, the server caches the first
 * response under that key, retries replay the cached response.
 *
 * **Mismatched-body semantics.** A retry that reuses the same
 * key but submits a different body (or method, or path) is
 * rejected with `422 Unprocessable Entity` (per Stripe's
 * `idempotency-mismatch` documentation). The contract: pick a
 * fresh `Idempotency-Key` if the request shape changed; never
 * overwrite an existing entry with a different body.
 *
 * **Race semantics.** Two concurrent requests with the same
 * key: the first to call `tryReserve` wins and runs the
 * handler. The second gets `409 Conflict` (NOT a replay —
 * the in-flight request has not yet produced a response, so
 * replaying "the cached entry" would be lying).
 *
 * **In-process scope.** The default
 * {@see \Framework\Http\Idempotency\InMemoryIdempotencyStore} is
 * per-process; the
 * {@see \Framework\Http\Idempotency\FilesystemIdempotencyStore}
 * is per-host via atomic-rename + `flock(LOCK_NB)`. Neither is
 * a substitute for Redis / Memcached in a multi-instance
 * deployment — see the docblock on
 * {@see \Framework\Http\Idempotency\IdempotencyStoreInterface}.
 */
final class IdempotencyKeyMiddleware implements MiddlewareInterface
{
    public const string ATTR_IDEMPOTENCY_KEY = 'idempotency_key';
    public const string ATTR_IDEMPOTENCY_REPLAY = 'idempotency_replay';
    public const string HEADER_IDEMPOTENCY_KEY = 'Idempotency-Key';
    public const string HEADER_REPLAYED = 'Idempotency-Replayed';

    /**
     * @param list<string> $methods HTTP methods that participate
     *     in idempotency. Default `['POST', 'PUT', 'PATCH', 'DELETE']` —
     *     the unsafe verbs that have a real "duplicate = duplicate
     *     side-effect" failure mode. GETs are excluded (they are
     *     already idempotent by HTTP spec).
     * @param list<string> $requiredOn Subset of `$methods` for
     *     which the `Idempotency-Key` header is MANDATORY. Default
     *     `['POST', 'PUT']` — payments and order creations. PATCH
     *     and DELETE are optional. Set to `$methods` to make
     *     every unsafe method require the key.
     * @param int $maxKeyLength Reject keys longer than this
     *     (default 255). Prevents a malicious client from
     *     filling the store with 4KB keys.
     */
    public function __construct(
        private readonly IdempotencyStoreInterface $store = new InMemoryIdempotencyStore(),
        private readonly int $ttl = 86_400,
        private readonly int $maxKeyLength = 255,
        private readonly array $methods = ['POST', 'PUT', 'PATCH', 'DELETE'],
        private readonly array $requiredOn = ['POST', 'PUT'],
    ) {
        if ($this->ttl < 1) {
            throw new InvalidArgumentException('IdempotencyKeyMiddleware: ttl must be >= 1');
        }
        if ($this->maxKeyLength < 1) {
            throw new InvalidArgumentException('IdempotencyKeyMiddleware: maxKeyLength must be >= 1');
        }
        foreach ($this->methods as $m) {
            if (!in_array($m, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
                throw new InvalidArgumentException(
                    "IdempotencyKeyMiddleware: unknown HTTP method '{$m}'",
                );
            }
        }
    }

    public function process(Request $request, callable $next): Response
    {
        $method = strtoupper($request->method);
        if (!in_array($method, $this->methods, true)) {
            return $next($request);
        }

        $rawKey = $request->header(self::HEADER_IDEMPOTENCY_KEY);
        if ($rawKey === null || $rawKey === '') {
            if (in_array($method, $this->requiredOn, true)) {
                throw new BadRequestHttpException(
                    "Idempotency-Key header is required for {$method} {$request->path}",
                );
            }
            return $next($request);
        }

        $key = $this->normalizeKey($rawKey);
        if ($key === '') {
            throw new BadRequestHttpException('Idempotency-Key is empty after normalisation');
        }
        if (strlen($key) > $this->maxKeyLength) {
            throw new BadRequestHttpException(
                "Idempotency-Key exceeds maximum length of {$this->maxKeyLength} characters",
            );
        }

        $bodyHash = hash('sha256', $request->body);

        if (!$this->store->tryReserve($key, $method, $request->path, $bodyHash)) {
            throw new ConflictHttpException(
                "Idempotency-Key '{$key}' is currently in flight on another request",
            );
        }

        try {
            $existing = $this->store->get($key, $method, $request->path, $bodyHash);
        } catch (IdempotencyConflictException $e) {
            throw new \Framework\Http\Exception\UnprocessableEntityHttpException(
                $e->getMessage(),
                previous: $e,
            );
        }
        if ($existing !== null) {
            return $this->replay($existing);
        }

        $response = $next($request);

        $this->store->put(
            $key,
            $method,
            $request->path,
            $bodyHash,
            new \Framework\Http\Idempotency\IdempotencyEntry(
                status: $response->status,
                body: $response->body,
                headers: $response->headers,
                cookies: $response->cookies,
                createdAt: time(),
            ),
        );

        return $response;
    }

    /**
     * Replay a stored entry on a retry. Sets
     * `Idempotency-Replayed: true` so the client can tell
     * a replay from the original, and re-emits any
     * `Set-Cookie` headers that the original response set
     * (the retry must observe the same session, JWT refresh,
     * etc.).
     */
    private function replay(\Framework\Http\Idempotency\IdempotencyEntry $entry): Response
    {
        $response = new Response(
            status: $entry->status,
            body: $entry->body,
            headers: $entry->headers + [self::HEADER_REPLAYED => 'true'],
            cookies: $entry->cookies,
        );
        return $response;
    }

    /**
     * Trim whitespace and reject control bytes. The store
     * keys on the normalised value, so "  K " and "K" share
     * a slot — the canonical "key" is the trimmed form.
     */
    private function normalizeKey(string $raw): string
    {
        $trimmed = trim($raw);
        if (preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1) {
            throw new BadRequestHttpException('Idempotency-Key contains control characters');
        }
        return $trimmed;
    }
}
