<?php

declare(strict_types=1);

namespace Framework\Tests\Integration;

use Framework\Tests\Support\LiveHttpTestCase;

final class HttpEndToEndTest extends LiveHttpTestCase
{
    protected function extraEnv(): array
    {
        return ['APP_TRUSTED_PROXIES' => '127.0.0.1'];
    }

    public function testGetRootReturnsHtml(): void
    {
        $response = $this->liveRequest('GET', '/');

        self::assertSame(200, $response['code']);
        self::assertStringContainsString('text/html', $response['headers']['Content-Type'] ?? '');
        self::assertStringContainsString('Framework', $response['body']);
    }

    public function testGetJsonReturnsJson(): void
    {
        $response = $this->liveRequest('GET', '/json');

        self::assertSame(200, $response['code']);
        self::assertStringContainsString('application/json', $response['headers']['Content-Type'] ?? '');

        $data = json_decode($response['body'], true);
        self::assertIsArray($data);
        self::assertSame('PHP 8.5', $data['framework']);
    }

    public function testGetWithPathParam(): void
    {
        $response = $this->liveRequest('GET', '/hello/world');

        self::assertSame(200, $response['code']);
        self::assertStringContainsString('Hello, world!', $response['body']);
    }

    public function testUnmatchedRouteReturnsRfc7807ProblemDetails(): void
    {
        $response = $this->liveRequest('GET', '/does-not-exist');

        self::assertSame(404, $response['code']);
        self::assertStringContainsString('application/problem+json', $response['headers']['Content-Type'] ?? '');

        $body = json_decode($response['body'], true);
        self::assertIsArray($body);
        self::assertSame(404, $body['status']);
        self::assertSame('Not Found', $body['title']);
        self::assertSame('about:blank', $body['type']);
    }

    public function testFormUrlencodedPostIsParsed(): void
    {
        $response = $this->liveRaw('POST', '/api/v1/form', [
            'Content-Type: application/x-www-form-urlencoded',
        ], 'name=Alice&age=30');

        self::assertSame(200, $response['code']);
        $body = json_decode($response['body'], true);
        self::assertIsArray($body);
        self::assertSame('Alice', $body['received']['name']);
        self::assertSame('30', $body['received']['age']);
    }

    public function testBoomIsAvailableInDefaultEnv(): void
    {
        $response = $this->liveRequest('GET', '/boom');

        self::assertSame(404, $response['code']);
        self::assertStringContainsString('application/problem+json', $response['headers']['Content-Type'] ?? '');

        $body = json_decode($response['body'], true);
        self::assertIsArray($body);
        self::assertSame(404, $body['status']);
        self::assertStringContainsString('deliberate', $body['detail'] ?? '');
    }

    public function testMultipartUploadEchoesParsedFileMetadata(): void
    {
        $boundary = 'X-E2E-BOUND';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"note\"\r\n\r\nhello\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"data.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\nfile body content\r\n"
            . "--{$boundary}--\r\n";

        $response = $this->liveRaw('POST', '/api/v1/upload', [
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ], $body);

        self::assertSame(200, $response['code']);

        $payload = json_decode($response['body'], true);
        self::assertIsArray($payload, 'Response body: ' . $response['body']);
        self::assertSame(1, $payload['file_count'] ?? -1, 'Payload: ' . json_encode($payload));
        self::assertSame(['file'], $payload['fields']);
        self::assertSame('data.txt', $payload['files'][0]['name']);
        self::assertSame('text/plain', $payload['files'][0]['type']);
        self::assertSame(17, $payload['files'][0]['size']);
    }

    public function testSecurityHeadersAreAddedToResponse(): void
    {
        $response = $this->liveRequest('GET', '/json');

        self::assertSame(200, $response['code']);
        self::assertSame('nosniff', $response['headers']['X-Content-Type-Options'] ?? null);
        self::assertSame('DENY', $response['headers']['X-Frame-Options'] ?? null);
        self::assertSame('strict-origin-when-cross-origin', $response['headers']['Referrer-Policy'] ?? null);
        $csp = $response['headers']['Content-Security-Policy'] ?? '';
        self::assertMatchesRegularExpression(
            "/^default-src 'self'; script-src 'self' 'nonce-[A-Za-z0-9_-]{22}'; style-src 'self' 'nonce-[A-Za-z0-9_-]{22}'\$/",
            $csp,
        );
        self::assertSame('max-age=31536000; includeSubDomains', $response['headers']['Strict-Transport-Security'] ?? null);
    }

    public function testCorsPreflightFromWhitelistedOrigin(): void
    {
        $response = $this->liveRaw('OPTIONS', '/api/v1/users', [
            'Origin: http://localhost:3000',
            'Access-Control-Request-Method: GET',
        ]);

        self::assertSame(204, $response['code']);
        self::assertSame('http://localhost:3000', $response['headers']['Access-Control-Allow-Origin'] ?? '');
        self::assertStringContainsString('GET', $response['headers']['Access-Control-Allow-Methods'] ?? '');
        self::assertSame('Origin, Access-Control-Request-Method, Access-Control-Request-Headers', $response['headers']['Vary'] ?? '');
        self::assertSame('true', $response['headers']['Access-Control-Allow-Credentials'] ?? '');
    }

    public function testCorsPreflightFromNonWhitelistedOrigin(): void
    {
        $response = $this->liveRaw('OPTIONS', '/api/v1/users', [
            'Origin: https://evil.example.com',
            'Access-Control-Request-Method: GET',
        ]);

        self::assertSame(403, $response['code']);
        self::assertStringContainsString('application/problem+json', $response['headers']['Content-Type'] ?? '');
    }

    public function testGetFormReturnsHtmlWithCsrfTokenAndSetsCookie(): void
    {
        $response = $this->liveRequest('GET', '/form');

        self::assertSame(200, $response['code']);
        self::assertStringContainsString('text/html', $response['headers']['Content-Type'] ?? '');
        self::assertStringContainsString('name="_token"', $response['body'], 'Form must contain a _token field');

        $setCookie = $response['headers']['Set-Cookie'] ?? '';
        self::assertStringContainsString('csrf_token=', $setCookie, 'First visit to /form must set csrf_token cookie');
        self::assertStringContainsString('HttpOnly', $setCookie);
    }

    public function testPostSubmitWithValidCsrfTokenSucceeds(): void
    {
        $formResponse = $this->liveRaw('GET', '/form');
        self::assertSame(200, $formResponse['code']);

        preg_match('/name="_token" value="([^"]+)"/', $formResponse['body'], $tokenMatch);
        self::assertArrayHasKey(1, $tokenMatch, 'Form HTML must contain a _token field with value');
        $token = $tokenMatch[1];

        preg_match('/Set-Cookie:\s*csrf_token=([^;\r\n]+)/i', $formResponse['raw'], $cookieMatch);
        self::assertArrayHasKey(1, $cookieMatch, 'First GET /form must set csrf_token cookie. Raw: ' . $formResponse['raw']);
        $cookieValue = trim($cookieMatch[1]);

        $postResponse = $this->liveRaw('POST', '/submit', [
            'Content-Type: application/x-www-form-urlencoded',
            'X-CSRF-Token: ' . $token,
            'Cookie: csrf_token=' . $cookieValue,
        ], '_token=' . urlencode($token) . '&name=Alice');

        self::assertSame(200, $postResponse['code'], 'POST /submit with valid CSRF must succeed. Body: ' . $postResponse['body']);
        $payload = json_decode($postResponse['body'], true);
        self::assertIsArray($payload);
        self::assertTrue($payload['ok']);
        self::assertSame('Alice', $payload['name']);
    }

    public function testPostSubmitWithoutCsrfTokenReturns400(): void
    {
        $postResponse = $this->liveRaw('POST', '/submit', [
            'Content-Type: application/x-www-form-urlencoded',
        ], 'name=Alice');

        self::assertSame(400, $postResponse['code'], 'POST /submit without CSRF must be 400. Body: ' . $postResponse['body']);
        self::assertStringContainsString('application/problem+json', $postResponse['headers']['Content-Type'] ?? '');
        $payload = json_decode($postResponse['body'], true);
        self::assertIsArray($payload);
        self::assertSame(400, $payload['status']);
        self::assertStringContainsString('CSRF', $payload['title'] . ($payload['detail'] ?? ''));
    }

    public function testGzipCompressesLargeJsonResponseWhenAcceptEncodingGzip(): void
    {
        $response = $this->liveRaw('GET', '/api/v1/large', [
            'Accept-Encoding: gzip',
        ]);

        self::assertSame(200, $response['code']);
        self::assertSame('gzip', $response['headers']['Content-Encoding'] ?? '', 'Response should be gzipped');
        self::assertStringContainsString('Accept-Encoding', $response['headers']['Vary'] ?? '');

        $decoded = gzdecode($response['body']);
        self::assertIsString($decoded, 'Body must be valid gzip data. First bytes (hex): ' . bin2hex(substr($response['body'], 0, 4)));
        $payload = json_decode($decoded, true);
        self::assertIsArray($payload);
        $data = $payload['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount(100, $data);
    }

    public function testLargeResponseWithoutAcceptEncodingIsNotCompressed(): void
    {
        $response = $this->liveRequest('GET', '/api/v1/large');

        self::assertSame(200, $response['code']);
        self::assertSame('', $response['headers']['Content-Encoding'] ?? '', 'Without Accept-Encoding: gzip, response must not be gzipped');

        $payload = json_decode($response['body'], true);
        self::assertIsArray($payload);
        $data = $payload['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount(100, $data);
    }

    public function testPostUserWithValidBodyReturns201(): void
    {
        $response = $this->liveRaw('POST', '/api/v1/users', [
            'Content-Type: application/json',
        ], json_encode(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30]));

        self::assertSame(201, $response['code'], 'POST /api/v1/users with valid body must be 201. Body: ' . $response['body']);

        $body = json_decode($response['body'], true);
        self::assertIsArray($body);
        self::assertSame('Alice', $body['name']);
        self::assertSame('alice@example.com', $body['email']);
        self::assertSame(30, $body['age']);
    }

    public function testPostUserWithInvalidBodyReturns422WithErrors(): void
    {
        $response = $this->liveRaw('POST', '/api/v1/users', [
            'Content-Type: application/json',
        ], json_encode(['name' => 'not-an-email', 'email' => 'not-an-email', 'age' => -5]));

        self::assertSame(422, $response['code'], 'POST /api/v1/users with invalid body must be 422. Body: ' . $response['body']);
        self::assertSame('application/problem+json', $response['headers']['Content-Type'] ?? '');

        $body = json_decode($response['body'], true);
        self::assertIsArray($body);
        self::assertSame(422, $body['status']);
        self::assertSame('Unprocessable Entity', $body['title']);
        self::assertArrayHasKey('errors', $body);
        self::assertNotEmpty($body['errors']);
    }
}
