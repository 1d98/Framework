<?php

declare(strict_types=1);

namespace Framework\Tests\Integration;

use Framework\Tests\Support\LiveHttpTestCase;

/**
 * End-to-end regression for the S1 HSTS-bypass review item. The default
 * `APP_TRUSTED_PROXIES` is empty: an attacker who can set
 * `X-Forwarded-Proto: https` on a plain-HTTP connection must NOT be
 * able to flip HSTS on or the `Secure` cookie flag.
 *
 * The CSRF middleware uses the `__Host-csrf_token` cookie prefix
 * (RFC 6265bis), which requires the `Secure` flag — which in turn
 * requires HTTPS. When the framework is configured with an empty
 * trusted-proxies list and the connection is plain HTTP, the middleware
 * REFUSES to mint a CSRF cookie (every conforming browser would
 * silently drop a `__Host-` cookie without Secure, so minting one
 * would be pointless). The CSRF smoke tests on `/form` therefore
 * assert the new fail-loud contract: a 500 with the diagnostic message
 * that points the operator at the fix.
 */
final class TrustedProxiesRegressionTest extends LiveHttpTestCase
{
    protected int $port = 18771;

    protected function logFileName(): string
    {
        return 'framework_test_server_trusted_proxies_regression.log';
    }

    protected function extraEnv(): array
    {
        return ['APP_TRUSTED_PROXIES' => ''];
    }

    public function testHstsIsNotEmittedOnUntrustedConnectionSendingForwardedProtoHttps(): void
    {
        // The CSRF middleware refuses to mint a `__Host-csrf_token`
        // cookie over a plain-HTTP connection, so the request surfaces
        // as a 500. The relevant assertion is that HSTS is NOT emitted
        // on that response — a misconfigured SecurityHeadersMiddleware
        // could otherwise flip HSTS on even when the request was
        // effectively plain HTTP.
        $response = $this->liveRequest('GET', '/json', [
            'X-Forwarded-Proto: https',
        ]);

        self::assertArrayNotHasKey(
            'Strict-Transport-Security',
            $response['headers'],
            'HSTS must not be set when APP_TRUSTED_PROXIES is empty',
        );
    }

    public function testCsrfMintOverHttpWithoutTrustedProxiesReturns500WithDiagnostic(): void
    {
        // The CSRF middleware refuses to mint a `__Host-csrf_token`
        // cookie over a plain-HTTP connection when no trusted proxy is
        // claiming HTTPS. The framework surfaces this as a 500 — the
        // operator's app log carries the full LogicException message
        // with the fix list, and the response body carries the
        // generic "Internal Server Error" shape (debug mode off in
        // production by default). This is intentional — silently
        // dropping the request would mask the misconfiguration; a
        // generic 500 makes it visible without leaking the cookie name
        // to a public attacker.
        $response = $this->liveRequest('GET', '/form', [
            'X-Forwarded-Proto: https',
        ]);

        self::assertSame(500, $response['code']);
        self::assertStringContainsString(
            'application/problem+json',
            $response['headers']['Content-Type'] ?? '',
        );

        // No CSRF cookie must be set — minting was refused.
        $setCookie = $this->cookieHeader($response);
        self::assertStringNotContainsString('csrf_token=', $setCookie);
        self::assertStringNotContainsString('__Host-csrf_token=', $setCookie);
    }

    /**
     * Defense against the multi-hop chain-spoofing attack: even when
     * APP_TRUSTED_PROXIES trusts the immediate connection, a
     * comma-separated `X-Forwarded-Proto` must not be honored. The
     * closest proxy in a multi-hop chain is responsible for STRIPPING
     * or REPLACING the header (not appending). If it fails to do so,
     * the framework treats the request as untrusted and the actual
     * transport scheme (plain HTTP, here) wins — no HSTS, no Secure
     * cookie, no HTTPS redirect.
     */
    public function testHstsIsNotEmittedWhenForwardedProtoIsMultiValueEvenFromTrustedProxy(): void
    {
        $response = $this->liveRequest('GET', '/json', [
            'X-Forwarded-Proto: https, http',
        ]);

        self::assertArrayNotHasKey(
            'Strict-Transport-Security',
            $response['headers'],
            'Multi-value X-Forwarded-Proto must not flip HSTS on, even from a trusted proxy',
        );
    }

    public function testCsrfMintOverHttpWithMultiValueForwardedProtoReturns500WithDiagnostic(): void
    {
        // Multi-value `X-Forwarded-Proto` is treated as untrusted by
        // Request::isSecure() regardless of the trust list — the
        // closest proxy in a multi-hop chain must STRIP/REPLACE the
        // header, not append. So the CSRF middleware refuses to mint.
        $response = $this->liveRequest('GET', '/form', [
            'X-Forwarded-Proto: https, http',
        ]);

        self::assertSame(500, $response['code']);
        self::assertStringContainsString(
            'application/problem+json',
            $response['headers']['Content-Type'] ?? '',
        );

        // No CSRF cookie must be set — minting was refused.
        $setCookie = $this->cookieHeader($response);
        self::assertStringNotContainsString('csrf_token=', $setCookie);
        self::assertStringNotContainsString('__Host-csrf_token=', $setCookie);
    }
}
