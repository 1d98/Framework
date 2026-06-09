<?php

declare(strict_types=1);

namespace Framework\Tests\Integration;

use Framework\Tests\Support\LiveHttpTestCase;

/**
 * End-to-end behavior of `APP_TRUSTED_PROXIES=127.0.0.1,::1` — the
 * canonical "PHP-FPM behind nginx on the same host" deployment. The
 * server is bound to 127.0.0.1, every request is from 127.0.0.1, and
 * the trust list covers that hop. HSTS / Secure cookies / HTTPS
 * pass-through must all work as if the request were truly over TLS.
 */
final class TrustedProxiesIntegrationTest extends LiveHttpTestCase
{
    protected int $port = 18769;

    protected function logFileName(): string
    {
        return 'framework_test_server_trusted_proxies.log';
    }

    protected function extraEnv(): array
    {
        return [
            'APP_TRUSTED_PROXIES' => '127.0.0.1,::1',
        ];
    }

    public function testHstsIsEmittedWhenRequestComesFromTrustedProxy(): void
    {
        $response = $this->liveRequest('GET', '/json', [
            'X-Forwarded-Proto: https',
        ]);

        self::assertSame(200, $response['code']);
        self::assertSame('max-age=31536000; includeSubDomains', $response['headers']['Strict-Transport-Security'] ?? null);
    }

    public function testCsrfCookieIsSecureWhenRequestComesFromTrustedProxy(): void
    {
        $response = $this->liveRequest('GET', '/form', [
            'X-Forwarded-Proto: https',
        ]);

        self::assertSame(200, $response['code']);
        $setCookie = $this->cookieHeader($response);
        self::assertStringContainsString('csrf_token=', $setCookie);
        self::assertMatchesRegularExpression(
            '/\bSecure\b/i',
            $setCookie,
            'csrf_token cookie from trusted-proxy https request must set Secure flag',
        );
    }
}
