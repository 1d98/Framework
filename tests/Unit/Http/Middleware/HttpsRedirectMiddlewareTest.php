<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Middleware;

use Framework\Exception\ConfigException;
use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Middleware\HttpsRedirectMiddleware;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpsRedirectMiddleware::class)]
final class HttpsRedirectMiddlewareTest extends TestCase
{
    public function testConstructorThrowsOnInvalidStatusCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('statusCode must be 301 or 308');

        new HttpsRedirectMiddleware(statusCode: 302, trustedHosts: ['example.com']);
    }

    public function testConstructorThrowsWhenTrustedHostsEmpty(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('trustedHosts must be configured');

        new HttpsRedirectMiddleware();
    }

    public function testConstructorThrowsOnEmptyStringInTrustedHosts(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('non-empty strings');

        new HttpsRedirectMiddleware(trustedHosts: ['example.com', '']);
    }

    public function testHttpRequestRedirectsWith301(): void
    {
        $middleware = new HttpsRedirectMiddleware(trustedHosts: ['example.com']);
        $request = new Request('GET', '/api/v1/users', '', ['host' => 'example.com']);

        $called = false;
        $response = $middleware->process($request, static function () use (&$called): Response {
            $called = true;
            return Response::json(['ok' => true]);
        });

        self::assertSame(301, $response->status);
        self::assertSame('https://example.com/api/v1/users', $response->headers['Location']);
        self::assertFalse($called, 'Next handler must not be called on HTTP request');
    }

    public function testHttpsRequestPassesThrough(): void
    {
        $response = $this->withRemoteAddr('127.0.0.1', function (): Response {
            $middleware = new HttpsRedirectMiddleware(
                trustedHosts: ['example.com'],
                trustedProxies: ['127.0.0.1'],
            );
            $request = new Request('GET', '/api/v1/users', '', [
                'host' => 'example.com',
                'x-forwarded-proto' => 'https',
            ]);
            return $middleware->process($request, static fn(): Response => Response::json(['ok' => true]));
        });

        self::assertSame(200, $response->status);
        self::assertArrayNotHasKey('Location', $response->headers);
    }

    public function test308StatusCodePreservesMethod(): void
    {
        $middleware = new HttpsRedirectMiddleware(statusCode: 308, trustedHosts: ['api.example.com']);
        $request = new Request('POST', '/api/v1/submit', '', ['host' => 'api.example.com']);

        $response = $middleware->process($request, static fn(): Response => Response::json([]));

        self::assertSame(308, $response->status);
        self::assertSame('https://api.example.com/api/v1/submit', $response->headers['Location']);
    }

    public function testQueryStringPreservedInLocation(): void
    {
        $middleware = new HttpsRedirectMiddleware(trustedHosts: ['example.com']);
        $request = new Request('GET', '/search', 'q=hello&page=2', ['host' => 'example.com']);

        $response = $middleware->process($request, static fn(): Response => Response::json([]));

        self::assertSame('https://example.com/search?q=hello&page=2', $response->headers['Location']);
    }

    public function testEmptyQueryStringOmitsQuestionMark(): void
    {
        $middleware = new HttpsRedirectMiddleware(trustedHosts: ['example.com']);
        $request = new Request('GET', '/', '', ['host' => 'example.com']);

        $response = $middleware->process($request, static fn(): Response => Response::json([]));

        self::assertSame('https://example.com/', $response->headers['Location']);
    }

    public function testMissingHostFallsBackToLocalhost(): void
    {
        $middleware = new HttpsRedirectMiddleware(trustedHosts: ['example.com', 'localhost']);
        $request = new Request('GET', '/');

        $response = $middleware->process($request, static fn(): Response => Response::json([]));

        self::assertSame('https://localhost/', $response->headers['Location']);
    }

    public function testUntrustedHostFallsBackToFirstTrustedPattern(): void
    {
        $middleware = new HttpsRedirectMiddleware(trustedHosts: ['example.com']);
        $request = new Request('GET', '/login', '', ['host' => 'evil.com']);

        $response = $middleware->process($request, static fn(): Response => Response::json([]));

        self::assertSame(301, $response->status);
        self::assertSame('https://example.com/login', $response->headers['Location']);
    }

    public function testCrlfHostHeaderThrows400InsteadOfRedirecting(): void
    {
        $middleware = new HttpsRedirectMiddleware(trustedHosts: ['example.com']);
        $request = new Request('GET', '/login', '', ['host' => "example.com\r\nX-Evil: 1"]);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid Host header');

        $middleware->process($request, static fn(): Response => Response::json([]));
    }

    public function testRedirectsWhenForwardedProtoComesFromUntrustedIp(): void
    {
        $response = $this->withRemoteAddr('198.51.100.5', function (): Response {
            $middleware = new HttpsRedirectMiddleware(
                trustedHosts: ['example.com'],
                trustedProxies: ['127.0.0.1'],
            );
            $request = new Request('GET', '/login', '', [
                'host' => 'example.com',
                'x-forwarded-proto' => 'https',
            ]);
            return $middleware->process($request, static fn(): Response => Response::json(['ok' => true]));
        });

        self::assertSame(301, $response->status);
        self::assertSame('https://example.com/login', $response->headers['Location']);
    }

    public function testRejectsMultiValueForwardedProtoAndStillRedirects(): void
    {
        $response = $this->withRemoteAddr('127.0.0.1', function (): Response {
            $middleware = new HttpsRedirectMiddleware(
                trustedHosts: ['example.com'],
                trustedProxies: ['127.0.0.1'],
            );
            $request = new Request('GET', '/login', '', [
                'host' => 'example.com',
                'x-forwarded-proto' => 'https, http',
            ]);
            return $middleware->process($request, static fn(): Response => Response::json(['ok' => true]));
        });

        self::assertSame(301, $response->status);
        self::assertSame('https://example.com/login', $response->headers['Location']);
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withRemoteAddr(string $addr, callable $fn): mixed
    {
        $previous = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = $addr;
        try {
            return $fn();
        } finally {
            if ($previous === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous;
            }
        }
    }
}
