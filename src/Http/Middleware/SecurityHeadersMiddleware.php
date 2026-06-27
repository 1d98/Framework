<?php

declare(strict_types=1);

namespace Framework\Http\Middleware;

use Framework\Http\Request\Request;
use Framework\Http\Response\Response;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public const DEFAULT_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Content-Security-Policy' => "default-src 'self'; frame-ancestors 'none'",
    ];

    public const ATTR_CSP_NONCE = 'csp_nonce';

    private const string NONCE_PATTERN = '/\A[A-Za-z0-9_-]{22}\z/';

    /**
     * @param array<string, string|null> $headers Header map. null = disable. Missing = use default.
     * @param int|null $hstsMaxAge HSTS max-age in seconds. null disables HSTS entirely.
     * @param bool $hstsIncludeSubdomains Append `includeSubDomains` directive.
     * @param bool $hstsPreload Append `preload` directive (hstspreload.org submission).
     * @param string|null $csp Full Content-Security-Policy override. null = build default with nonce.
     * @param list<string>|null $trustedProxies CIDR / IP list allowed to set
     *     `X-Forwarded-Proto`. When `null` or `[]`, the header is NEVER trusted
     *     and HSTS is only emitted on a genuine HTTPS connection. See
     *     {@see \Framework\Http\Request\Request::isSecure()}.
     */
    public function __construct(
        private readonly array $headers = [],
        private readonly ?int $hstsMaxAge = 31536000,
        private readonly bool $hstsIncludeSubdomains = true,
        private readonly bool $hstsPreload = false,
        private readonly ?string $csp = null,
        private readonly ?array $trustedProxies = null,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $nonce = $this->cspNonce($request);
        $request = $request->withAttribute(self::ATTR_CSP_NONCE, $nonce);

        $effective = self::DEFAULT_HEADERS;
        $cspOverriddenByUser = false;
        foreach ($this->headers as $name => $value) {
            if ($value === null) {
                unset($effective[$name]);
            } else {
                $effective[$name] = $value;
                if ($name === 'Content-Security-Policy') {
                    $cspOverriddenByUser = true;
                }
            }
        }

        if ($this->csp !== null) {
            $effective['Content-Security-Policy'] = $this->csp;
        } elseif (!$cspOverriddenByUser) {
            $effective['Content-Security-Policy'] = sprintf(
                "default-src 'self'; script-src 'self' 'nonce-%s'; style-src 'self' 'nonce-%s'; frame-ancestors 'none'",
                $nonce,
                $nonce,
            );
        }

        $hsts = $this->buildHstsValue();
        if ($hsts !== null && $request->isSecure($this->trustedProxies) && !array_key_exists('Strict-Transport-Security', $this->headers)) {
            $effective['Strict-Transport-Security'] = $hsts;
        }

        /** @var Response $response */
        $response = $next($request);

        foreach ($effective as $name => $value) {
            if (!$this->responseHasHeader($response, $name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        if (!$this->responseHasHeader($response, 'X-CSP-Nonce')) {
            $response = $response->withHeader('X-CSP-Nonce', $nonce);
        }

        return $response;
    }

    /**
     * Resolve the per-request CSP nonce. Reads from the request's attributes
     * (Request-scoped cache); generates a fresh 128-bit URL-safe base64 nonce
     * (22 characters) on first access.
     */
    public function cspNonce(Request $request): string
    {
        $existing = $request->getAttribute(self::ATTR_CSP_NONCE);
        if (is_string($existing) && preg_match(self::NONCE_PATTERN, $existing) === 1) {
            return $existing;
        }

        $raw = base64_encode(random_bytes(16));
        $urlSafe = strtr(rtrim($raw, '='), ['+' => '-', '/' => '_']);

        return $urlSafe;
    }

    private function buildHstsValue(): ?string
    {
        if ($this->hstsMaxAge === null) {
            return null;
        }

        $parts = ['max-age=' . $this->hstsMaxAge];
        if ($this->hstsIncludeSubdomains) {
            $parts[] = 'includeSubDomains';
        }
        if ($this->hstsPreload) {
            $parts[] = 'preload';
        }

        return implode('; ', $parts);
    }

    private function responseHasHeader(Response $response, string $name): bool
    {
        $needle = strtolower($name);
        foreach ($response->headers as $key => $_value) {
            if (strtolower((string) $key) === $needle) {
                return true;
            }
        }
        return false;
    }
}
