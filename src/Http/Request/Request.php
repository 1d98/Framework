<?php

declare(strict_types=1);

namespace Framework\Http\Request;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Exception\PayloadTooLargeHttpException;
use Framework\Http\SafeParseStr;
use Framework\Http\UploadedFile;
use Framework\Validation\ValidationException;
use Framework\Validation\Validator;
use LogicException;

final readonly class Request
{
    /**
     * Default cap on request body size (10 MiB). Prevents DoS via memory
     * exhaustion when a malicious client sends an unbounded payload.
     *
     * Note: strlen counts bytes; parse_str later counts key separators.
     * Mixed metric is fine in practice — the cap is defense-in-depth, not the parsing budget.
     */
    public const int MAX_BODY_BYTES = 10 * 1024 * 1024;

    /**
     * Sensible dev defaults for trusted Host patterns. Production deployments
     * MUST override these — the framework refuses to redirect traffic with
     * an unconfigured trust list (see {@see \Framework\Http\Middleware\HttpsRedirectMiddleware}).
     *
     * @var list<string>
     */
    public const array TRUSTED_HOSTS_DEFAULT = ['localhost', '127.0.0.1', '*.localhost'];

    /**
     * Loopback CIDRs. Convenient for trusted-proxy lists in single-host
     * dev environments (e.g. `php -S 127.0.0.1:8000`).
     *
     * @var list<string>
     */
    public const array TRUST_LOOPBACK = ['127.0.0.0/8', '::1/128'];

    /**
     * RFC 1918 private IPv4 ranges. Convenient for trusted-proxy lists
     * when the app sits behind a load balancer or reverse proxy on a
     * private network.
     *
     * @var list<string>
     */
    public const array TRUST_PRIVATE = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    private const int REQUEST_ID_MAX_LENGTH = 64;

    private const string REQUEST_ID_PATTERN = '/\A[A-Za-z0-9_-]+\z/';

    public string $id;

    /**
     * @var array<string, mixed>
     */
    public array $attributes;

    /**
     * Host-related value object extracted as part of the A3 god-class
     * split (RB-5). Owns the raw Host header, the transport-level HTTPS
     * snapshot, the immediate-connection `REMOTE_ADDR`, and the
     * trusted-proxy list. Always non-null on a Request returned from
     * {@see RequestFactory::fromGlobals()}; may be a default empty VO
     * on direct-constructed requests that did not supply one.
     */
    private readonly RequestHost $host;

    /**
     * Sibling memo holding the lazy {@see RequestBinder} cache. Lives
     * off-`Request` because the class is `final readonly` and cannot
     * expose a writable field — the binder must be filled in-place on
     * the first `bind()` / `bindWith()` call to avoid O(N) allocations
     * for a controller that binds inside a loop (R8 review, P2-9).
     */
    private readonly RequestMemo $memo;

    /**
     * @param array<string, string> $headers
     * @param array<string, string|list<string>>|null $form
     * @param array<string, UploadedFile|list<UploadedFile>>|null $files
     * @param array<string, string> $cookies
     * @param array<string, mixed>|null $attributes
     * @param list<string>|null $trustedProxies DEPRECATED since 0.5.1 —
     *     use the named-arg `host:` with a {@see RequestHost} instead.
     *     The value is honored ONLY when the `host:` arg is null
     *     (legacy direct-construction path that does not supply a
     *     `RequestHost`); it is folded into a default `RequestHost`
     *     so existing callers using `trustedProxies: [...]` keep
     *     working. When BOTH `trustedProxies:` and `host:` are
     *     passed, `host:` wins. This arg will be removed in the
     *     next minor release.
     *
     * Invariant: when `$body` is non-empty, its length must not exceed the
     * effective cap (`$maxBodyBytes ?? self::MAX_BODY_BYTES`). This makes
     * the cap a HARD property of every Request instance — direct
     * construction cannot bypass the SAPI-boundary check performed by
     * {@see self::fromGlobals()}. The cap can be relaxed (e.g. for test
     * fixtures that build large synthetic bodies) by passing
     * `maxBodyBytes: PHP_INT_MAX` explicitly.
     *
     * @see RequestFactory::fromGlobals() — the SAPI-bound entry point
     *      that uses one effective cap for both the body read and this
     *      constructor, guaranteeing the two cannot diverge.
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $queryString = '',
        public array $headers = [],
        public string $body = '',
        public mixed $json = null,
        public ?array $form = null,
        public ?array $files = null,
        public array $cookies = [],
        public ?string $csrfToken = null,
        public ?Validator $validator = null,
        public ?int $maxBodyBytes = null,
        ?string $id = null,
        ?array $attributes = null,
        ?array $trustedProxies = null,
        ?RequestHost $host = null,
        ?RequestMemo $memo = null,
    ) {
        $this->id = $id ?? self::resolveRequestId($this->headers);
        $this->attributes = $attributes ?? [];

        if ($host !== null) {
            $this->host = $host;
        } else {
            $this->host = new RequestHost(
                host: RequestHost::snapshotHostHeader(),
                isSecure: RequestHost::snapshotTransportHttps(),
                remoteAddr: RequestHost::snapshotRemoteAddr(),
                trustedProxies: $trustedProxies,
            );
        }
        $this->memo = $memo ?? new RequestMemo();

        $effectiveMax = $maxBodyBytes ?? self::MAX_BODY_BYTES;
        if ($body !== '' && strlen($body) > $effectiveMax) {
            throw new PayloadTooLargeHttpException(
                'Request body exceeds cap of ' . $effectiveMax . ' bytes (got ' . strlen($body) . '). Direct construction bypasses the SAPI boundary; use Request::fromGlobals() for untrusted input.',
            );
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private static function resolveRequestId(array $headers): string
    {
        $candidate = $headers['x-request-id'] ?? $headers['x-correlation-id'] ?? null;
        if (is_string($candidate) && self::isValidRequestId($candidate)) {
            return $candidate;
        }
        return bin2hex(random_bytes(8));
    }

    private static function isValidRequestId(string $value): bool
    {
        if ($value === '' || strlen($value) > self::REQUEST_ID_MAX_LENGTH) {
            return false;
        }
        return preg_match(self::REQUEST_ID_PATTERN, $value) === 1;
    }

    /**
     * Thin wrapper around {@see RequestFactory::fromGlobals()} kept for
     * backward compatibility — see R6 (Request split into DTO + factory
     * + binder). New code should depend on the factory directly.
     */
    public static function fromGlobals(?int $maxBodyBytes = null): self
    {
        return RequestFactory::fromGlobals(
            maxBodyBytes: $maxBodyBytes ?? self::MAX_BODY_BYTES,
        );
    }

    /**
     * Backward-compat shim for tests that exercise the body cap against
     * a synthetic stream. Delegates to
     * {@see RequestFactory::readStreamWithCap()}; new code should call
     * the factory method directly.
     *
     * @see RequestFactory::readStreamWithCap()
     *
     * @param resource $stream
     */
    public static function readStreamWithCap($stream, int $maxBodyBytes): string
    {
        return RequestFactory::readStreamWithCap($stream, $maxBodyBytes);
    }

    /**
     * @return array<string, string|list<string>>
     * @throws BadRequestHttpException When the query string exceeds the
     *         key / nesting caps in {@see SafeParseStr}.
     */
    public function query(): array
    {
        $result = SafeParseStr::parse($this->queryString);
        /** @var array<string, string|list<string>> $result */
        return $result;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Whether the request was received over HTTPS, or terminated at a
     * trusted proxy that asserts HTTPS via `X-Forwarded-Proto`.
     *
     * Default behavior (`$trustedProxies === null`) is **strict**: the
     * `X-Forwarded-Proto` header is NEVER trusted, and the answer is
     * driven entirely by the underlying transport snapshot captured
     * at construction (via the SAPI-bound {@see RequestHost} VO).
     * This is the safe default — an attacker setting
     * `X-Forwarded-Proto: https` over a plain-HTTP connection can no
     * longer flip HSTS on (`SecurityHeadersMiddleware`), disable the
     * HTTPS redirector (`HttpsRedirectMiddleware`), or force the
     * `Secure` cookie flag (`CsrfMiddleware`).
     *
     * When a non-empty `$trustedProxies` list is passed, the immediate
     * connection's `REMOTE_ADDR` is checked against the list (exact
     * IPs and CIDR ranges, IPv4 + IPv6). The `X-Forwarded-Proto` header
     * is honored only on that match. With an empty `$trustedProxies`
     * list, behavior is identical to the strict default.
     *
     * A `X-Forwarded-Proto` header with more than one comma-separated
     * value is NEVER honored, even from a trusted proxy — the closest
     * proxy in a multi-hop chain must be configured to STRIP or
     * REPLACE the header (not append), or the request is treated as
     * untrusted and the actual transport scheme wins. This prevents
     * chain-spoofing where an upstream hop's leftmost `https` token
     * would otherwise make the request appear secure end-to-end.
     *
     * Convenient pre-built lists are exposed as
     * {@see self::TRUST_LOOPBACK} and {@see self::TRUST_PRIVATE}.
     *
     * @param list<string>|null $trustedProxies  CIDRs / exact IPs allowed
     *     to set `X-Forwarded-Proto`. `null` (the default) and `[]`
     *     both disable header trust. Falls back to the list stored on
     *     the {@see RequestHost} VO when null.
     * @param string|null $remoteAddr  Override the immediate connection
     *     address. Defaults to the `REMOTE_ADDR` snapshot captured at
     *     construction. Useful in tests where `$_SERVER` was empty at
     *     snapshot time and the caller still wants to exercise the
     *     trust check.
     */
    public function isSecure(?array $trustedProxies = null, ?string $remoteAddr = null): bool
    {
        return $this->host->isSecure(
            $trustedProxies,
            $remoteAddr,
            $this->header('X-Forwarded-Proto'),
        );
    }

    /**
     * The actual transport-level scheme for the immediate connection.
     * Does NOT consult `X-Forwarded-Proto` — that header is honored
     * only via {@see self::isSecure()} and only for trusted proxies.
     */
    public function isHttps(): bool
    {
        return $this->host->isSecure;
    }

    /**
     * Return the client IP address.
     *
     * Default behavior (`$trustedProxies === null` or `[]`) is **strict**:
     * the value of `X-Forwarded-For` is NEVER consulted, and the
     * answer is the immediate connection's `REMOTE_ADDR` — exactly
     * what the SAPI recorded at request time (snapshotted into the
     * {@see RequestHost} VO at construction). This is the safe default
     * for a bare app that is not behind a reverse proxy: an attacker
     * setting `X-Forwarded-For: 1.2.3.4` over a direct connection
     * cannot spoof their key in a rate limiter.
     *
     * When a non-empty `$trustedProxies` list is passed, the
     * immediate connection's `REMOTE_ADDR` is checked against the
     * list (exact IPs and CIDR ranges, IPv4 + IPv6, via
     * {@see CidrMatcher}). Only on that match is `X-Forwarded-For`
     * honored. The returned value is the **leftmost** token of the
     * header, per RFC 7239 §5.2 / RFC 7230 §7 — the leftmost
     * address is the originating client, the rightmost is the
     * closest proxy.
     *
     * **Security caveat:** the leftmost token is **only as
     * trustworthy as the edge proxy**. If the edge is
     * misconfigured to APPEND to an inbound `X-Forwarded-For`
     * instead of REPLACE / STRIP, an attacker can prepend an
     * arbitrary value and pick their bucket key. This caveat is
     * why the strict default ignores the header entirely.
     *
     * Returns `null` when `REMOTE_ADDR` is missing or empty
     * (e.g. CLI / built-in server quirk / test fixture without
     * `$_SERVER`).
     *
     * Convenient pre-built lists are exposed as
     * {@see self::TRUST_LOOPBACK} and {@see self::TRUST_PRIVATE}.
     *
     * @param list<string>|null $trustedProxies  CIDRs / exact IPs allowed
     *     to set `X-Forwarded-For`. `null` (the default) and `[]`
     *     both disable header trust. Falls back to the list stored
     *     on the {@see RequestHost} VO when null.
     * @param string|null $remoteAddr  Override the immediate connection
     *     address. Defaults to the `REMOTE_ADDR` snapshot captured at
     *     construction.
     */
    public function ip(?array $trustedProxies = null, ?string $remoteAddr = null): ?string
    {
        return $this->host->ip(
            $trustedProxies,
            $remoteAddr,
            $this->header('X-Forwarded-For'),
        );
    }

    /**
     * Return the validated Host header for the request.
     *
     * Without `$trustedHosts` the raw Host value is returned as-is
     * (legacy behavior, kept for backward compatibility). Callers
     * that reflect the result into a `Location:` header, redirect
     * URL, or any other security-sensitive surface MUST pass an
     * explicit list — otherwise an attacker can poison the Host
     * header (DNS rebinding, open redirect).
     *
     * Matching rules when `$trustedHosts` is provided:
     *  - The header is checked for CRLF / NUL injection first;
     *    offenders cause a 400 `BadRequestHttpException` regardless
     *    of the list.
     *  - Any `host:port` form has the port stripped before matching.
     *  - Comparison is case-insensitive (lowercased).
     *  - A pattern of the form `*.example.com` matches
     *    `example.com` itself and any single-label subdomain
     *    (`api.example.com`, `a.b.example.com`).
     *  - A bare label (`example.com`) matches only that exact host.
     *  - If the host does not match any pattern, the first pattern
     *    is returned as a safe default. Passing an empty list
     *    throws, because "trust nothing" + "fall back to a default"
     *    is a contradiction.
     *
     * The actual validation lives on the {@see RequestHost} VO; this
     * method delegates there, passing the live `$this->headers['host']`
     * so direct-constructed test fixtures that supply a Host header
     * via the `$headers` ctor arg are honored (the snapshot on the VO
     * is empty in that case).
     *
     * @param list<string>|null $trustedHosts Optional list of trusted host patterns.
     * @throws BadRequestHttpException When the Host header contains CRLF / NUL injection.
     * @throws LogicException When `$trustedHosts` is a non-null empty list.
     */
    public function host(?array $trustedHosts = null): string
    {
        return $this->host->host($trustedHosts, $this->header('Host'));
    }

    /**
     * @param list<string>|null $trustedProxies  See {@see self::isSecure()}.
     * @param string|null $remoteAddr  See {@see self::isSecure()}.
     */
    public function scheme(?array $trustedProxies = null, ?string $remoteAddr = null): string
    {
        return $this->host->scheme(
            $trustedProxies,
            $remoteAddr,
            $this->header('X-Forwarded-Proto'),
        );
    }

    public function withJson(mixed $data): self
    {
        return $this->copy(['json' => $data]);
    }

    public function json(): mixed
    {
        return $this->json;
    }

    /**
     * @param array<string, string|list<string>> $data
     */
    public function withForm(array $data): self
    {
        return $this->copy(['form' => $data]);
    }

    /**
     * @return array<string, string|list<string>>|null
     */
    public function form(): ?array
    {
        return $this->form;
    }

    /**
     * @param array<string, UploadedFile|list<UploadedFile>> $files
     */
    public function withFiles(array $files): self
    {
        return $this->copy(['files' => $files]);
    }

    /**
     * @return array<string, UploadedFile|list<UploadedFile>>|null
     */
    public function files(): ?array
    {
        return $this->files;
    }

    /**
     * @return array<string, string>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function maxBodyBytes(): int
    {
        return $this->maxBodyBytes ?? self::MAX_BODY_BYTES;
    }

    public function withCsrfToken(string $token): self
    {
        return $this->copy(['csrfToken' => $token]);
    }

    public function csrfToken(): ?string
    {
        return $this->csrfToken;
    }

    public function withValidator(Validator $v): self
    {
        return $this->copy(['validator' => $v]);
    }

    public function withId(string $id): self
    {
        return $this->copy(['id' => $id]);
    }

    /**
     * Return a new {@see Request} with the trusted-proxy list stored on
     * the instance. Subsequent no-arg calls to {@see self::isSecure()}
     * and {@see self::ip()} will consult this list as a fallback.
     * Per-call arguments to `isSecure()` / `ip()` always win over the
     * stored list.
     *
     * @param list<string>|null $trustedProxies CIDRs / exact IPs allowed
     *     to set `X-Forwarded-Proto` / `X-Forwarded-For`. `null` and
     *     `[]` both disable header trust.
     *
     * @deprecated since 0.5.1 — use {@see self::withHost()} with a
     *     {@see RequestHost} instead. The trust list now lives on the
     *     `RequestHost` VO. This method is kept as a thin wrapper for
     *     backward compatibility; it will be removed in the next
     *     minor release.
     *
     * @internal Kept for backward compatibility only; new code must
     *     use {@see self::withHost()} with a {@see RequestHost}.
     */
    public function withTrustedProxies(?array $trustedProxies): self
    {
        return $this->withHost(new RequestHost(
            host: $this->host->host,
            isSecure: $this->host->isSecure,
            remoteAddr: $this->host->remoteAddr,
            trustedProxies: $trustedProxies,
        ));
    }

    /**
     * Return a new {@see Request} with the supplied {@see RequestHost}
     * value object. The host VO owns the Host header, transport-level
     * HTTPS snapshot, `REMOTE_ADDR`, and trusted-proxy list. This is
     * the canonical mutator introduced by the RB-5 split; callers that
     * need to change the trust list should prefer this over the
     * deprecated {@see self::withTrustedProxies()}.
     */
    public function withHost(RequestHost $host): self
    {
        return $this->copy(['host' => $host]);
    }

    public function validator(): ?Validator
    {
        return $this->validator;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function hasAttribute(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        return $this->copy(['attributes' => [...$this->attributes, $name => $value]]);
    }

    /**
     * Internal helper that builds a new {@see Request} from this one,
     * replacing only the properties listed in `$overrides`. Each entry
     * is `property name => new value`; the other 16 properties are
     * carried over from `$this`. The shared `RequestMemo` (with its
     * lazy `RequestBinder` cache) is always propagated by reference,
     * so the child request can never accidentally allocate a fresh
     * binder — see R9 contract.
     *
     * @param array{
     *     method?: string,
     *     path?: string,
     *     queryString?: string,
     *     headers?: array<string, string>,
     *     body?: string,
     *     json?: mixed,
     *     form?: array<string, string|list<string>>|null,
     *     files?: array<string, UploadedFile|list<UploadedFile>>|null,
     *     cookies?: array<string, string>,
     *     csrfToken?: string,
     *     validator?: Validator|null,
     *     maxBodyBytes?: int|null,
     *     id?: string,
     *     attributes?: array<string, mixed>,
     *     host?: RequestHost,
     * } $overrides
     */
    private function copy(array $overrides = []): self
    {
        return new self(
            $overrides['method'] ?? $this->method,
            $overrides['path'] ?? $this->path,
            $overrides['queryString'] ?? $this->queryString,
            $overrides['headers'] ?? $this->headers,
            $overrides['body'] ?? $this->body,
            array_key_exists('json', $overrides) ? $overrides['json'] : $this->json,
            $overrides['form'] ?? $this->form,
            $overrides['files'] ?? $this->files,
            $overrides['cookies'] ?? $this->cookies,
            $overrides['csrfToken'] ?? $this->csrfToken,
            $overrides['validator'] ?? $this->validator,
            $overrides['maxBodyBytes'] ?? $this->maxBodyBytes,
            $overrides['id'] ?? $this->id,
            $overrides['attributes'] ?? $this->attributes,
            null,
            $overrides['host'] ?? $this->host,
            $this->memo,
        );
    }

    /**
     * Validate body (JSON priority, form fallback) and build a DTO instance.
     * Thin wrapper around {@see RequestBinder::bind()} kept for backward
     * compatibility — see R6 (Request split into DTO + factory + binder).
     *
     * The binder is constructed lazily, **once per Request instance**,
     * and reused across subsequent calls — important for controllers
     * that bind inside a loop (R8 review, P2-9).
     *
     * @template T of object
     * @param class-string<T> $dtoClass
     * @return T
     * @throws ValidationException If validation fails
     * @throws LogicException If validator is not configured
     */
    public function bind(string $dtoClass): object
    {
        if ($this->validator === null) {
            throw new LogicException('Validator not configured; pass via Request::__construct or withValidator()');
        }
        return $this->binder()->bind($this, $dtoClass);
    }

    /**
     * Validate explicit data (ignores Request body) and build a DTO instance.
     * Thin wrapper around {@see RequestBinder::bindWith()} kept for backward
     * compatibility — see R6 (Request split into DTO + factory + binder).
     *
     * The binder is constructed lazily, **once per Request instance**,
     * and reused across subsequent calls.
     *
     * @template T of object
     * @param class-string<T> $dtoClass
     * @param array<string, mixed> $data
     * @return T
     * @throws ValidationException If validation fails
     * @throws LogicException If validator is not configured
     */
    public function bindWith(array $data, string $dtoClass): object
    {
        if ($this->validator === null) {
            throw new LogicException('Validator not configured; pass via Request::__construct or withValidator()');
        }
        return $this->binder()->bindWith($this, $data, $dtoClass);
    }

    /**
     * Return a new {@see Request} with the given {@see RequestBinder}
     * pre-installed — subsequent `bind()` / `bindWith()` calls will
     * skip the lazy default and route through `$binder` directly.
     *
     * The pre-installed binder is shared with this Request (not cloned),
     * so callers that want a request-scoped binder should pass a fresh
     * instance; the binder itself is stateless and safe to share.
     */
    public function withBinder(RequestBinder $binder): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->queryString,
            $this->headers,
            $this->body,
            $this->json,
            $this->form,
            $this->files,
            $this->cookies,
            $this->csrfToken,
            $this->validator,
            $this->maxBodyBytes,
            $this->id,
            $this->attributes,
            null,
            $this->host,
            new RequestMemo($binder),
        );
    }

    /**
     * Lazy accessor for the per-instance {@see RequestBinder}. The
     * first call builds a default binder (wrapping the configured
     * `Validator`); subsequent calls return the same instance, so a
     * controller binding inside a loop does not re-allocate.
     *
     * Callers MUST have a non-null `$validator` — `bind()` /
     * `bindWith()` enforce this with a {@see LogicException} before
     * reaching this accessor.
     */
    private function binder(): RequestBinder
    {
        if ($this->memo->binder === null) {
            $this->memo->binder = new RequestBinder($this->validator);
        }
        return $this->memo->binder;
    }
}
