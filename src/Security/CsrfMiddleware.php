<?php

declare(strict_types=1);

namespace Framework\Security;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Middleware\MiddlewareInterface;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Logging\LoggerInterface;
use Framework\Logging\NullLogger;
use InvalidArgumentException;

final class CsrfMiddleware implements MiddlewareInterface
{
    public const COOKIE_NAME = 'csrf_token';
    public const HEADER_NAME = 'X-CSRF-Token';
    public const FORM_FIELD = '_token';

    /** @var list<string> */
    private readonly array $exemptPrefixes;
    /** @var list<string> */
    private readonly array $exemptPaths;
    private readonly LoggerInterface $logger;

    /**
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

    public function process(Request $request, callable $next): Response
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

    private function handleSafe(Request $request, callable $next): Response
    {
        $raw = $request->cookie(self::COOKIE_NAME);

        if ($raw === null || $raw === '') {
            $token = bin2hex(random_bytes(32));
            $response = $this->callNext($request->withCsrfToken($token), $next);
            $cookie = $this->jar->makeCookie(
                self::COOKIE_NAME,
                $token,
                expiresAt: 0,
                secure: $request->isSecure($this->trustedProxies),
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

    private function handleUnsafe(Request $request, callable $next): Response
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

    private function callNext(Request $request, callable $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        return $response;
    }
}
