<?php

declare(strict_types=1);

namespace Framework\Security;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Middleware\MiddlewareInterface;
use Framework\Http\Request\Request;
use Framework\Http\Response\ResponseInterface;
use Framework\Logging\LoggerInterface;
use Framework\Logging\NullLogger;
use InvalidArgumentException;

final class CsrfMiddleware implements MiddlewareInterface
{
    /**
     * The `__Host-` prefix pins the cookie to: Secure, no Domain attribute,
     * Path=/. RFC 6265bis. Every browser since ~2020 enforces this. It is a
     * defense-in-depth measure: a sub-domain (e.g. an `images.` subdomain
     * with a lax cookie policy) cannot shadow the CSRF cookie, and the
     * Secure flag means the cookie cannot leak over plain HTTP. See
     * https://datatracker.ietf.org/doc/html/draft-ietf-httpbis-rfc6265bis-03
     */
    public const COOKIE_NAME = '__Host-csrf_token';
    public const HEADER_NAME = 'X-CSRF-Token';
    public const FORM_FIELD = '_token';

    /**
     * Current CSRF cookie payload format. The signed cookie body is
     * `<version>:<token>:<issuedAt>` (Unix seconds). Version 1 embeds
     * a timestamp so the middleware can enforce a TTL on validation,
     * closing the long-lived-CSRF-token leak window (an XSS-leaked
     * or logged token is usable for at most `$ttl` seconds, not the
     * entire session lifetime).
     *
     * Version 0 (pre-0.7.2) is a bare 64-char hex token with no
     * timestamp; it is accepted during the `$graceTtl` migration
     * window after the first legacy token is observed.
     */
    private const int PAYLOAD_VERSION = 1;

    /**
     * Unix timestamp after which legacy (v0, no-timestamp) payloads
     * are rejected. Initialised eagerly on the first request seen by
     * this process — see {@see self::process()}. Anchoring the cutoff
     * on first request (not first v0 sight) closes a worker-skew gap:
     * a worker that never sees a v0 token would otherwise leave the
     * v0 window open indefinitely, because the lazy `??=` would
     * never fire. Per-process state is intentional: the grace window
     * starts when the new code first serves a deploy, regardless of
     * which PHP-FPM / Octane worker handled the first request.
     */
    private static ?int $v0CutoffTimestamp = null;

    /** @var list<string> */
    private readonly array $exemptPrefixes;
    /** @var list<string> */
    private readonly array $exemptPaths;
    private readonly LoggerInterface $logger;

    /**
     * Cookie name is `__Host-csrf_token` (RFC 6265bis prefix), which means:
     *  - the cookie MUST be served with the `Secure` flag,
     *  - the cookie MUST NOT carry a `Domain` attribute,
     *  - the cookie `Path` MUST be `/`.
     * Browsers enforce all three and silently drop the cookie if any rule
     * is violated. As a consequence the middleware REFUSES to mint the
     * cookie over a connection it cannot prove is HTTPS — see
     * {@see self::handleSafe()}. For TLS-terminating deployments, pass
     * `trustedProxies` so {@see Request::isSecure()} honours
     * `X-Forwarded-Proto: https`.
     *
     * @param list<string> $exemptPrefixes Path prefixes exempted from CSRF. Each MUST end with `/` (or be `/`).
     *                                      Example: `['/api/']` matches `/api/users` and `/api/v1/echo`, but NOT `/apiv1`.
     *                                      Passing a string without a trailing `/` throws, to prevent the silent
     *                                      `/api` → `/apiv1` / `/apocalypse` footgun. For API endpoints using bearer-style auth.
     * @param list<string> $exemptPaths   Exact paths exempted from CSRF. Each must be a non-empty string; matched
     *                                      only when the request path equals it byte-for-byte. Use this for health
     *                                      checks and similar single endpoints. Example: `['/health']` exempts `/health`
     *                                      but NOT `/health-check` or `/healthy`.
     * @param ?LoggerInterface $logger    Optional logger. When `null` (the default) the middleware stays silent and
     *                                      only uses exceptions for diagnostics; pass `NullLogger` (or any real
     *                                      logger) to receive an info-level notice when both the `X-CSRF-Token`
     *                                      header and the `_token` form field are present in the same request.
     * @param list<string>|null $trustedProxies  CIDR / IP list allowed to set `X-Forwarded-Proto`.
     *                                      When `null` or `[]`, the header is NEVER trusted and the
     *                                      `Secure` cookie flag is set only on a genuine HTTPS
     *                                      connection. See {@see \Framework\Http\Request\Request::isSecure()}.
     * @param int $ttl        Token validity in seconds. Tokens older than this are rejected on
     *                                      unsafe requests (HTTP 400 — `CSRF token mismatch: token expired`).
     *                                      Default 3600 (1 hour). Set to `0` to disable TTL enforcement
     *                                      (legacy long-lived behaviour — NOT recommended; the whole
     *                                      point of this parameter is to bound the leak window of an
     *                                      XSS- or log-leaked CSRF token). Tokens stamped in the future
     *                                      (clock skew) are ALWAYS rejected regardless of this value.
     * @param int $graceTtl   Grace period for legacy (pre-0.7.2) tokens in seconds. A bare-hex
     *                                      payload with no timestamp is accepted for this many seconds
     *                                      after the first legacy token is observed by this process,
     *                                      then rejected. Default 604800 (7 days). Set to `0` to refuse
     *                                      legacy tokens immediately and force a hard cut-over.
     *
     * **Token TTL.** As of 0.7.2, the signed cookie payload embeds a
     * versioned format `<version>:<token>:<issuedAt>` instead of a bare
     * `<token>`. Tokens older than `$ttl` seconds (default 1 hour) are
     * rejected on unsafe requests. This closes the long-lived-CSRF-token
     * leak window: an XSS-leaked or logged token is usable for at most
     * `$ttl` seconds, not the entire session lifetime.
     *
     * **Migration window for pre-0.7.2 tokens.** Legacy payloads (bare
     * hex, no timestamp) are accepted for `$graceTtl` seconds (default
     * 7 days) after the first legacy token is observed by this process.
     * This is a smooth rollout: existing users are not logged out by a
     * deploy, but operators can still retire old tokens by configuring
     * a shorter `graceTtl` or `0` for an immediate cut-over.
     *
     * @throws InvalidArgumentException if any prefix does not end with `/`, if any entry is not a non-empty string,
     *                                      or if `$ttl` / `$graceTtl` is negative.
     * @throws \LogicException         when a new cookie must be minted over an
     *                                      insecure connection (the `__Host-` prefix requires Secure).
     */
    public function __construct(
        private readonly SignedCookieJar $jar,
        array $exemptPrefixes = [],
        array $exemptPaths = [],
        ?LoggerInterface $logger = null,
        private readonly ?array $trustedProxies = null,
        private readonly int $ttl = 3600,
        private readonly int $graceTtl = 604800,
    ) {
        // Guard against the `exemptPrefixes: ['/']` footgun: `str_starts_with($path, '/')`
        // is true for every request, so this configuration would silently disable CSRF
        // for the entire site. Operators sometimes copy-paste a placeholder prefix while
        // wiring up routes. Fail loudly at boot instead of on the first request.
        if ($exemptPrefixes === ['/']) {
            throw new InvalidArgumentException(
                "CsrfMiddleware exemptPrefixes cannot be just ['/'] — that disables CSRF for every path. "
                . "Use exemptPrefixes: ['/api/', '/webhooks/'] for specific prefixes, "
                . "or empty arrays if you want no exemptions.",
            );
        }
        foreach ($exemptPrefixes as $i => $entry) {
            if (!is_string($entry) || $entry === '' || !str_ends_with($entry, '/')) {
                throw new InvalidArgumentException(
                    "CsrfMiddleware: exemptPrefixes[$i] must be a non-empty string ending with '/', "
                    . "got: " . var_export($entry, true)
                    . " — prefix exemptions must end with '/' to prevent /api from matching /apiv1. "
                    . "Use exemptPaths for exact single-path exemptions.",
                );
            }
        }
        foreach ($exemptPaths as $i => $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new InvalidArgumentException(
                    "CsrfMiddleware: exemptPaths[$i] must be a non-empty string, got: " . var_export($entry, true),
                );
            }
        }
        if ($this->ttl < 0) {
            throw new InvalidArgumentException(
                "CsrfMiddleware: ttl must be >= 0, got {$this->ttl}",
            );
        }
        if ($this->graceTtl < 0) {
            throw new InvalidArgumentException(
                "CsrfMiddleware: graceTtl must be >= 0, got {$this->graceTtl}",
            );
        }
        $this->exemptPrefixes = array_values($exemptPrefixes);
        $this->exemptPaths = array_values($exemptPaths);
        $this->logger = $logger ?? new NullLogger();
    }

    public function process(Request $request, callable $next): ResponseInterface
    {
        // Eagerly anchor the v0 grace cutoff on the first request seen
        // by this worker (not the first v0 token). A worker that never
        // observes a v0 payload would otherwise leave the v0 window
        // open indefinitely, since the lazy `??=` would never fire.
        // Pinning on first request gives every worker a bounded
        // migration window from the moment it serves traffic.
        self::$v0CutoffTimestamp ??= time() + $this->graceTtl;

        if ($this->isExempt($request->path)) {
            return $this->callNext($request, $next);
        }

        $isSafe = in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true);

        if ($isSafe) {
            return $this->handleSafe($request, $next);
        }

        return $this->handleUnsafe($request, $next);
    }

    private function isExempt(string $path): bool
    {
        if (in_array($path, $this->exemptPaths, true)) {
            return true;
        }
        foreach ($this->exemptPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function handleSafe(Request $request, callable $next): ResponseInterface
    {
        $raw = $request->cookie(self::COOKIE_NAME);

        if ($raw === null || $raw === '') {
            return $this->mintFreshCookie($request, $next);
        }

        $existing = $this->jar->payload($raw);
        if ($existing !== null) {
            $validity = $this->parseAndValidate($existing);
            if ($validity['valid']) {
                return $this->callNext($request->withCsrfToken($validity['token']), $next);
            }
            // Existing cookie passed signature verification but is
            // either expired (v1 older than $ttl) or a legacy v0 payload
            // past its grace cutoff. Rotate: mint a fresh token now so
            // the user's next unsafe request has a valid cookie
            // available — distinct from the signature-failure case
            // below, which is the fixation-guard clearing path.
            return $this->mintFreshCookie($request, $next);
        }

        $response = $this->callNext($request, $next);

        return $response->withHeader(
            'Set-Cookie',
            $this->clearingSetCookieHeader($request->isSecure($this->trustedProxies)),
        );
    }

    /**
     * Mint a fresh `__Host-csrf_token` cookie and expose the bare
     * token to the downstream handler via `$request->withCsrfToken()`.
     *
     * The cookie payload is the versioned format
     * `<version>:<token>:<issuedAt>` (Unix seconds, base 10). The
     * bare token is what gets embedded in HTML forms
     * (`<input type="hidden" name="_token" value="…">`) and sent on
     * unsafe requests via the `X-CSRF-Token` header — the form/header
     * value never carries the timestamp, so a leaked form value alone
     * is still bound by the cookie-side TTL on the next validation.
     *
     * @throws \LogicException when the request is not provably HTTPS
     *                              (the `__Host-` cookie prefix requires
     *                              `Secure`, which requires TLS — see the
     *                              error message for the unblock steps).
     */
    private function mintFreshCookie(Request $request, callable $next): ResponseInterface
    {
        // The `__Host-` prefix requires the `Secure` flag, which in turn
        // requires HTTPS. If the request does not look secure (no TLS, no
        // trusted proxy claiming `X-Forwarded-Proto: https`), minting a
        // `__Host-csrf_token` cookie would be silently rejected by every
        // conforming browser — the user would never receive a CSRF token
        // and every state-changing request would 400. We fail loud at the
        // call site instead.
        if (!$request->isSecure($this->trustedProxies)) {
            throw new \LogicException(
                "CsrfMiddleware: refusing to mint a `__Host-csrf_token` cookie over an insecure connection. "
                . "The `__Host-` cookie prefix requires Secure, which requires HTTPS. "
                . "Fix one of:\n"
                . "  1. Serve the app over HTTPS (production: required).\n"
                . "  2. If you are behind a TLS-terminating proxy (load balancer, nginx, Cloudflare), "
                . "configure the worker to see HTTPS by passing `trustedProxies` to CsrfMiddleware so "
                . "`X-Forwarded-Proto: https` is honoured (see Request::isSecure()).\n"
                . "  3. If you really must run CSRF without TLS (dev only), exempt the unsafe paths via "
                . "`exemptPrefixes` / `exemptPaths` AND downgrade the cookie name to a non-`__Host-` value "
                . "via a subclass — never do this in production."
            );
        }

        $token = bin2hex(random_bytes(32));
        $issuedAt = time();
        $payload = self::PAYLOAD_VERSION . ':' . $token . ':' . $issuedAt;
        $response = $this->callNext($request->withCsrfToken($token), $next);
        $cookie = $this->jar->makeCookie(
            self::COOKIE_NAME,
            $payload,
            expiresAt: 0,  // session cookie — TTL is enforced by us on validation
            secure: true,
        );

        return $response->withCookie($cookie);
    }

    private function clearingSetCookieHeader(bool $secure): string
    {
        $parts = [self::COOKIE_NAME . '=', 'Max-Age=0', 'Path=/', 'HttpOnly', 'SameSite=Lax'];
        if ($secure) {
            $parts[] = 'Secure';
        }
        return implode('; ', $parts);
    }

    private function handleUnsafe(Request $request, callable $next): ResponseInterface
    {
        $expected = $this->jar->read($request, self::COOKIE_NAME);
        if ($expected === null) {
            throw new BadRequestHttpException('CSRF token mismatch: cookie missing');
        }

        $validity = $this->parseAndValidate($expected);
        if (!$validity['valid']) {
            $reason = $validity['reason'] === 'expired' ? 'token expired' : 'token malformed';
            throw new BadRequestHttpException("CSRF token mismatch: {$reason}");
        }

        $headerToken = $request->header(self::HEADER_NAME);

        $form = $request->form() ?? [];
        $formTokenRaw = array_key_exists(self::FORM_FIELD, $form) ? $form[self::FORM_FIELD] : null;
        if ($formTokenRaw !== null && !is_string($formTokenRaw)) {
            throw new BadRequestHttpException(
                "CSRF token mismatch: " . self::FORM_FIELD . " form field must be a scalar string; got "
                . get_debug_type($formTokenRaw) . " — most likely an HTML mistake such as name=\""
                . self::FORM_FIELD . "[]\" in the form, or duplicated name=\""
                . self::FORM_FIELD . "\" inputs. Use a single hidden input with name=\""
                . self::FORM_FIELD . "\".",
            );
        }
        $formToken = is_string($formTokenRaw) ? $formTokenRaw : null;

        if ($headerToken !== null && $formToken !== null) {
            $this->logger->info(
                'CsrfMiddleware: both X-CSRF-Token header and _token form field provided; header takes precedence.',
            );
        }

        $provided = $headerToken ?? $formToken;

        if ($provided === null || $provided === '') {
            throw new BadRequestHttpException('CSRF token mismatch: token not in request');
        }

        // `validity['token']` is the bare hex extracted from the versioned
        // payload (or the legacy payload itself for v0). The form/header
        // value never carries the timestamp — the timestamp lives only in
        // the cookie, so this comparison is the canonical "form token
        // matches cookie token" check.
        if (!hash_equals($validity['token'], $provided)) {
            throw new BadRequestHttpException('CSRF token mismatch: invalid token');
        }

        return $this->callNext($request->withCsrfToken($validity['token']), $next);
    }

    /**
     * Parse a CSRF cookie payload and check its TTL/grace status.
     *
     * Payload formats:
     *  - **v1 (current, 0.7.2+)**: `"<version>:<token>:<issuedAt>"`
     *    where `<version>` is `1`, `<token>` is 64 lowercase hex chars,
     *    and `<issuedAt>` is a Unix timestamp in seconds (base 10).
     *  - **v0 (legacy, pre-0.7.2)**: bare 64 lowercase hex chars,
     *    no timestamp. Accepted during the `$graceTtl` migration window
     *    after the first legacy token is observed by this process.
     *
     * Returns:
     *  - `['valid' => true, 'token' => '<bare-hex>']` on success
     *  - `['valid' => false, 'reason' => 'expired']` when the v1 token
     *    is older than `$ttl`, when the v1 timestamp is in the future
     *    (clock skew, treated as expired), or when a v0 legacy token
     *    is observed past its grace cutoff.
     *  - `['valid' => false, 'reason' => 'malformed']` when the payload
     *    does not match either format (wrong structure, non-hex chars,
     *    non-digit timestamp, wrong length, etc.).
     *
     * The legacy grace cutoff is **process-local** (a static
     * `$v0CutoffTimestamp` initialised eagerly in `process()` on the
     * first request, not on the first v0 observation — see the
     * docblock on `$v0CutoffTimestamp` for why). This is intentional:
     * the grace window starts when the new code first serves a deploy,
     * regardless of which PHP-FPM / Octane worker handled the first
     * request, AND regardless of which payloads that worker happens
     * to see. Different workers may set the cutoff within seconds of
     * each other; the bound (`$graceTtl`, default 7 days) is the
     * operator-facing guarantee.
     *
     * @return array{valid: true, token: string}|array{valid: false, reason: 'expired'|'malformed'}
     */
    private function parseAndValidate(string $payload): array
    {
        $prefix = self::PAYLOAD_VERSION . ':';
        if (str_starts_with($payload, $prefix)) {
            // v1 payload — `1:<token>:<issuedAt>`.
            $parts = explode(':', substr($payload, strlen($prefix)), 2);
            if (count($parts) !== 2) {
                return ['valid' => false, 'reason' => 'malformed'];
            }
            [$token, $issuedAtRaw] = $parts;
            if (!ctype_digit($issuedAtRaw)) {
                return ['valid' => false, 'reason' => 'malformed'];
            }
            if (!ctype_xdigit($token) || strlen($token) !== 64) {
                return ['valid' => false, 'reason' => 'malformed'];
            }
            $issuedAt = (int) $issuedAtRaw;
            $now = time();
            $age = $now - $issuedAt;
            // Clock skew: a token stamped in the future is rejected as
            // expired (we have no way to distinguish it from a forged
            // timestamp without a per-token replay store, and the cost
            // of accepting it — extending the effective TTL by the skew
            // — outweighs the cost of asking the client to re-mint).
            // The age cap (`$age > $ttl`) is skipped when `$ttl === 0`,
            // which is the documented "disable TTL enforcement" mode —
            // `0` means "no upper bound", NOT "reject anything older
            // than zero seconds".
            if ($age < 0) {
                return ['valid' => false, 'reason' => 'expired'];
            }
            if ($this->ttl > 0 && $age > $this->ttl) {
                return ['valid' => false, 'reason' => 'expired'];
            }
            return ['valid' => true, 'token' => $token];
        }

        // v0 legacy payload — bare 64-char hex, no timestamp.
        // We can't know when it was minted, so the only safe behaviour
        // is a deploy-time grace window: accept for `$graceTtl` seconds
        // after the new code first serves traffic in this process, then
        // refuse. The grace cutoff is anchored eagerly in `process()`
        // on the first request — see the docblock on
        // `$v0CutoffTimestamp` for the rationale (worker that never sees
        // a v0 token would otherwise leave the window open indefinitely).
        if (!ctype_xdigit($payload) || strlen($payload) !== 64) {
            return ['valid' => false, 'reason' => 'malformed'];
        }
        if (time() > self::$v0CutoffTimestamp) {
            return ['valid' => false, 'reason' => 'expired'];
        }
        return ['valid' => true, 'token' => $payload];
    }

    private function callNext(Request $request, callable $next): ResponseInterface
    {
        /** @var ResponseInterface $response */
        $response = $next($request);
        return $response;
    }
}
