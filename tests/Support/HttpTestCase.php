<?php

declare(strict_types=1);

namespace Framework\Tests\Support;

use Framework\Http\HttpKernel;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class HttpTestCase extends TestCase
{
    abstract protected function app(): HttpKernel;

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    protected function request(string $method, string $path, array $body = [], array $headers = []): Response
    {
        $hasBody = $method !== 'GET' && $method !== 'HEAD' && $body !== [];
        $payload = $hasBody ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $finalHeaders = $headers;
        if ($hasBody && !isset($headers['Content-Type'])) {
            $finalHeaders['Content-Type'] = 'application/json';
        }

        $request = new Request(
            strtoupper($method),
            $path,
            '',
            array_change_key_case($finalHeaders, CASE_LOWER),
            is_string($payload) ? $payload : '',
            $hasBody ? $body : null,
        );

        return $this->app()->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function jsonBody(Response $r): array
    {
        $decoded = json_decode($r->body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Response body is not valid JSON: ' . substr($r->body, 0, 80));
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    protected function assertProblem(Response $r, int $status, ?string $typeFragment = null): void
    {
        self::assertSame($status, $r->status);
        self::assertSame('application/problem+json', $r->headers['Content-Type'] ?? null);
        $body = $this->jsonBody($r);
        self::assertSame($status, $body['status'] ?? null);
        if ($typeFragment !== null) {
            $type = $body['type'] ?? '';
            self::assertIsString($type);
            self::assertStringContainsString($typeFragment, $type);
        }
    }
}
