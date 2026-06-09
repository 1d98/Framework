<?php

declare(strict_types=1);

namespace Framework\Tests\Integration;

use Framework\Tests\Support\LiveHttpTestCase;

final class HttpEndToEndProdTest extends LiveHttpTestCase
{
    protected int $port = 18767;

    protected function appEnv(): string
    {
        return 'prod';
    }

    protected function logFileName(): string
    {
        return 'framework_test_server_prod.log';
    }

    protected function extraEnv(): array
    {
        return [
            'APP_TRUSTED_HOSTS' => 'example.com,*.example.com',
            'APP_TRUSTED_PROXIES' => '127.0.0.1',
            'APP_SECRET' => 'a]1f9b7c2e4d6a8b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d0e1f2a3b4',
        ];
    }

    public function testBoomRouteIsNotRegisteredInProduction(): void
    {
        $response = $this->liveRequest('GET', '/boom');

        self::assertSame(404, $response['code']);
        self::assertStringContainsString('application/problem+json', $response['headers']['Content-Type'] ?? '');

        $body = json_decode($response['body'], true);
        self::assertIsArray($body);
        self::assertSame(404, $body['status']);
        $detail = is_string($body['detail'] ?? null) ? $body['detail'] : '';
        self::assertStringNotContainsString('deliberate', $detail);
    }

    public function testHttpRequestIsRedirectedToHttpsInProduction(): void
    {
        $response = $this->liveRaw('GET', '/some/path?q=1', [
            'X-Forwarded-Proto: http',
            'Host: example.com',
        ], null, false);

        self::assertSame(301, $response['code']);
        self::assertSame('https://example.com/some/path?q=1', $response['headers']['Location'] ?? '');
    }

    public function testUntrustedHostDoesNotProduceOpenRedirectInProduction(): void
    {
        $response = $this->liveRaw('GET', '/login?next=/dashboard', [
            'X-Forwarded-Proto: http',
            'Host: evil.com',
        ], null, false);

        $location = $response['headers']['Location'] ?? '';
        self::assertNotSame('https://evil.com/login?next=/dashboard', $location);
        self::assertStringNotContainsString('evil.com', $location);
        self::assertSame(301, $response['code']);
    }

    public function testHttpsRequestPassesThroughInProduction(): void
    {
        $response = $this->liveRequest('GET', '/json', [
            'Host: example.com',
        ]);

        self::assertSame(200, $response['code']);
        self::assertSame('nosniff', $response['headers']['X-Content-Type-Options'] ?? null);
        self::assertArrayNotHasKey('Location', $response['headers']);
    }

    public function testGzipCompressionWorksInProduction(): void
    {
        $response = $this->liveRaw('GET', '/api/v1/large', [
            'Accept-Encoding: gzip',
        ]);

        self::assertSame(200, $response['code']);
        self::assertSame('gzip', $response['headers']['Content-Encoding'] ?? '');

        $decoded = gzdecode($response['body']);
        self::assertIsString($decoded, 'Production response must be valid gzip');
        $payload = json_decode($decoded, true);
        self::assertIsArray($payload);
        $data = $payload['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount(100, $data);
    }
}
