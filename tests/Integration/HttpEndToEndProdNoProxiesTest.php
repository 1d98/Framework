<?php

declare(strict_types=1);

namespace Framework\Tests\Integration;

use Framework\Tests\Support\LiveHttpTestCase;

/**
 * Production environment with `APP_TRUSTED_PROXIES` deliberately empty
 * (the new safe default). The HttpsRedirectMiddleware is in the pipeline
 * in prod and must still redirect — the empty trust list means the
 * `X-Forwarded-Proto: https` header is ignored at every hop.
 */
final class HttpEndToEndProdNoProxiesTest extends LiveHttpTestCase
{
    protected int $port = 18773;

    protected function appEnv(): string
    {
        return 'prod';
    }

    protected function logFileName(): string
    {
        return 'framework_test_server_prod_no_proxies.log';
    }

    protected function extraEnv(): array
    {
        return [
            'APP_TRUSTED_HOSTS' => 'example.com,*.example.com',
            'APP_TRUSTED_PROXIES' => '',
            'APP_SECRET' => 'a]1f9b7c2e4d6a8b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d0e1f2a3b4',
        ];
    }

    public function testHttpsRedirectStillRedirectsWhenTrustedProxiesEmpty(): void
    {
        $response = $this->liveRaw('GET', '/some/path?q=1', [
            'X-Forwarded-Proto: https',
            'Host: example.com',
        ], null, false);

        self::assertSame(301, $response['code']);
        self::assertSame('https://example.com/some/path?q=1', $response['headers']['Location'] ?? '');
    }

    public function testHstsIsNotEmittedWhenTrustedProxiesEmptyInProduction(): void
    {
        $response = $this->liveRaw('GET', '/json', [
            'X-Forwarded-Proto: https',
            'Host: example.com',
        ], null, false);

        self::assertNotSame(200, $response['code']);
        self::assertArrayNotHasKey(
            'Strict-Transport-Security',
            $response['headers'],
            'Even on the redirect response, the HSTS header must not leak from a 301',
        );
    }
}
