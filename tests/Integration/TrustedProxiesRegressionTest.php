<?php

declare(strict_types=1);

namespace Framework\Tests\Integration;

use Framework\Tests\Support\LiveHttpTestCase;

/**
 * End-to-end regression for the S1 HSTS-bypass review item. The default
 * `APP_TRUSTED_PROXIES` is empty: an attacker who can set
 * `X-Forwarded-Proto: https` on a plain-HTTP connection must NOT be
 * able to flip HSTS on or the `Secure` cookie flag.
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
        $response = $this->liveRequest('GET', '/json', [
            'X-Forwarded-Proto: https',
        ]);

        self::assertSame(200, $response['code']);
        self::assertArrayNotHasKey(
            'Strict-Transport-Security',
            $response['headers'],
            'HSTS must not be set when APP_TRUSTED_PROXIES is empty',
        );
    }

    public function testCsrfCookieHasNoSecureFlagOnUntrustedConnectionSendingForwardedProtoHttps(): void
    {
        $response = $this->liveRequest('GET', '/form', [
            'X-Forwarded-Proto: https',
        ]);

        self::assertSame(200, $response['code']);
        $setCookie = $this->cookieHeader($response);
        self::assertStringContainsString('csrf_token=', $setCookie);
        self::assertDoesNotMatchRegularExpression(
            '/\bSecure\b/i',
            $setCookie,
            'csrf_token cookie must NOT set Secure when APP_TRUSTED_PROXIES is empty',
        );
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

        self::assertSame(200, $response['code']);
        self::assertArrayNotHasKey(
            'Strict-Transport-Security',
            $response['headers'],
            'Multi-value X-Forwarded-Proto must not flip HSTS on, even from a trusted proxy',
        );
    }

    public function testCsrfCookieHasNoSecureFlagWhenForwardedProtoIsMultiValueEvenFromTrustedProxy(): void
    {
        $response = $this->liveRequest('GET', '/form', [
            'X-Forwarded-Proto: https, http',
        ]);

        self::assertSame(200, $response['code']);
        $setCookie = $this->cookieHeader($response);
        self::assertStringContainsString('csrf_token=', $setCookie);
        self::assertDoesNotMatchRegularExpression(
            '/\bSecure\b/i',
            $setCookie,
            'csrf_token cookie must NOT set Secure when X-Forwarded-Proto has multiple values',
        );
    }
}
