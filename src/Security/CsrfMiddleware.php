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
     *
     * @throws InvalidArgumentException if any prefix does not end with `/`, or if any entry is not a non-empty string.
     * @throws \LogicException         when a new cookie must be minted over an
     *                                      insecure connection (the `__Host-` prefix requires Secure).
     */
    public function __construct(
        private readonly SignedCookieJar $jar,
        array $exemptPrefixes = [],
        array $exemptPaths = [],
        ?LoggerInterface $logger = null,
        private readonly ?array $trustedProxies = null,
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
        $this->exemptPrefixes = array_values($exemptPrefixes);
        $this->exemptPaths = array_values($exemptPaths);
        $this->logger = $logger ?? new NullLogger();
    }

    public function process(Request $request, callable $next): ResponseInterface
    {
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
            $response = $this->callNext($request->withCsrfToken($token), $next);
            $cookie = $this->jar->makeCookie(
                self::COOKIE_NAME,
                $token,
                expiresAt: 0,
                secure: true,
            );

            return $response->withCookie($cookie);
        }

        $existing = $this->jar->payload($raw);
        if ($existing !== null) {
            return $this->callNext($request->withCsrfToken($existing), $next);
        }

        $response = $this->callNext($request, $next);

        return $response->withHeader(
            'Set-Cookie',
            $this->clearingSetCookieHeader($request->isSecure($this->trustedProxies)),
        );
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

        if (!hash_equals($expected, $provided)) {
            throw new BadRequestHttpException('CSRF token mismatch: invalid token');
        }

        return $this->callNext($request->withCsrfToken($expected), $next);
    }

    private function callNext(Request $request, callable $next): ResponseInterface
    {
        /** @var ResponseInterface $response */
        $response = $next($request);
        return $response;
    }
}
