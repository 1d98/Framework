<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Middleware;

use Framework\Http\Middleware\CorsMiddleware;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CorsMiddleware::class)]
final class CorsMiddlewareTest extends TestCase
{
    public function testConstructorThrowsOnWildcardOriginWithCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot use "*" origin with credentials=true');

        new CorsMiddleware(origins: ['*'], credentials: true);
    }

    public function testAllowsWildcardOriginWithoutCredentialsButStrictMatches(): void
    {
        $middleware = new CorsMiddleware(origins: ['*'], credentials: false);

        $request = new Request('GET', '/api/users', '', ['origin' => '*']);
        $response = $middleware->process($request, static fn(): Response => Response::json([]));

        self::assertSame('*', $response->headers['Access-Control-Allow-Origin']);
    }

    public function testNoOriginHeaderPassesThroughWithoutCorsHeaders(): void
    {
        $middleware = new CorsMiddleware(origins: ['https://app.example.com']);
        $request = new Request('GET', '/');

        $response = $middleware->process($request, static fn(): Response => Response::json(['ok' => true]));

        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
        self::assertArrayNotHasKey('Vary', $response->headers);
        self::assertSame(200, $response->status);
    }

    public function testEmptyOriginPassesThroughWithoutCorsHeaders(): void
    {
        $middleware = new CorsMiddleware(origins: ['https://app.example.com']);
        $request = new Request('GET', '/', '', ['origin' => '   ']);

        $response = $middleware->process($request, static fn(): Response => Response::json(['ok' => true]));

        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
    }

    public function testPreflightFromWhitelistedOriginReturns204WithAllHeaders(): void
    {
        $middleware = new CorsMiddleware(
            origins: ['http://localhost:3000'],
            credentials: true,
        );
        $request = new Request(
            'OPTIONS',
            '/api/v1/users',
            '',
            [
                'origin' => 'http://localhost:3000',
                'access-control-request-method' => 'POST',
            ],
        );

        $response = $middleware->process($request, static function (): Response {
            self::fail('Preflight should short-circuit, handler must not be called');
        });

        self::assertSame(204, $response->status);
        self::assertSame('http://localhost:3000', $response->headers['Access-Control-Allow-Origin']);
        self::assertSame('GET, POST, PUT, PATCH, DELETE, OPTIONS', $response->headers['Access-Control-Allow-Methods']);
        self::assertSame('Content-Type, Authorization, X-CSRF-Token', $response->headers['Access-Control-Allow-Headers']);
        self::assertSame('300', $response->headers['Access-Control-Max-Age']);
        self::assertSame('Origin, Access-Control-Request-Method, Access-Control-Request-Headers', $response->headers['Vary']);
        self::assertSame('true', $response->headers['Access-Control-Allow-Credentials']);
    }

    public function testPreflightFromNonWhitelistedOriginReturns403ProblemJson(): void
    {
        $middleware = new CorsMiddleware(origins: ['https://app.example.com']);
        $request = new Request(
            'OPTIONS',
            '/api/v1/users',
            '',
            [
                'origin' => 'https://evil.example.com',
                'access-control-request-method' => 'POST',
            ],
        );

        $response = $middleware->process($request, static function (): Response {
            self::fail('Preflight should short-circuit, handler must not be called');
        });

        self::assertSame(403, $response->status);
        self::assertSame('application/problem+json', $response->headers['Content-Type']);
        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame(403, $body['status']);
        self::assertSame('CORS origin not allowed', $body['detail']);
    }

    public function testNonPreflightFromWhitelistedOriginAddsCorsHeaders(): void
    {
        $middleware = new CorsMiddleware(
            origins: ['http://localhost:3000'],
            exposeHeaders: ['X-Total-Count'],
        );
        $request = new Request('GET', '/api/users', '', ['origin' => 'http://localhost:3000']);

        $response = $middleware->process($request, static fn(): Response => Response::json(['users' => []]));

        self::assertSame(200, $response->status);
        self::assertSame('http://localhost:3000', $response->headers['Access-Control-Allow-Origin']);
        self::assertSame('Origin', $response->headers['Vary']);
        self::assertSame('X-Total-Count', $response->headers['Access-Control-Expose-Headers']);
    }

    public function testNonPreflightFromNonWhitelistedOriginPassesThroughWithoutCorsHeaders(): void
    {
        $middleware = new CorsMiddleware(origins: ['https://app.example.com']);
        $request = new Request('GET', '/api/users', '', ['origin' => 'https://evil.example.com']);

        $response = $middleware->process($request, static fn(): Response => Response::json(['users' => []]));

        self::assertSame(200, $response->status);
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
        self::assertSame('Origin', $response->headers['Vary']);
    }

    public function testNonPreflightOmitsCredentialsHeaderWhenDisabled(): void
    {
        $middleware = new CorsMiddleware(
            origins: ['http://localhost:3000'],
            credentials: false,
        );
        $request = new Request('GET', '/api/users', '', ['origin' => 'http://localhost:3000']);

        $response = $middleware->process($request, static fn(): Response => Response::json([]));

        self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $response->headers);
    }

    public function testCustomMethodsAndHeadersUsedInPreflight(): void
    {
        $middleware = new CorsMiddleware(
            origins: ['https://app.example.com'],
            methods: ['GET', 'POST'],
            headers: ['X-Custom-Header'],
        );
        $request = new Request(
            'OPTIONS',
            '/x',
            '',
            [
                'origin' => 'https://app.example.com',
                'access-control-request-method' => 'POST',
            ],
        );

        $response = $middleware->process($request, static function (): Response {
            self::fail('Preflight must short-circuit');
        });

        self::assertSame('GET, POST', $response->headers['Access-Control-Allow-Methods']);
        self::assertSame('X-Custom-Header', $response->headers['Access-Control-Allow-Headers']);
    }

    public function testCustomMaxAgeUsedInPreflight(): void
    {
        $middleware = new CorsMiddleware(
            origins: ['https://app.example.com'],
            maxAge: 3600,
        );
        $request = new Request(
            'OPTIONS',
            '/x',
            '',
            [
                'origin' => 'https://app.example.com',
                'access-control-request-method' => 'GET',
            ],
        );

        $response = $middleware->process($request, static function (): Response {
            self::fail('Preflight must short-circuit');
        });

        self::assertSame('3600', $response->headers['Access-Control-Max-Age']);
    }

    public function testOptionsWithoutAccessControlRequestMethodIsNotPreflight(): void
    {
        $middleware = new CorsMiddleware(origins: ['https://app.example.com']);
        $request = new Request('OPTIONS', '/x', '', ['origin' => 'https://app.example.com']);

        $response = $middleware->process($request, static fn(): Response => Response::json([]));

        self::assertSame(200, $response->status);
        self::assertSame('https://app.example.com', $response->headers['Access-Control-Allow-Origin']);
        self::assertArrayNotHasKey('Access-Control-Allow-Methods', $response->headers);
    }

    public function testPreflightVaryIncludesRequestMethodAndRequestHeaders(): void
    {
        $middleware = new CorsMiddleware(
            origins: ['https://app.example.com'],
            headers: ['Content-Type', 'Authorization', 'X-CSRF-Token'],
        );
        $request = new Request(
            'OPTIONS',
            '/api/v1/users',
            '',
            [
                'origin' => 'https://app.example.com',
                'access-control-request-method' => 'POST',
            ],
        );

        $response = $middleware->process($request, static function (): Response {
            self::fail('Preflight must short-circuit');
        });

        self::assertSame(
            'Origin, Access-Control-Request-Method, Access-Control-Request-Headers',
            $response->headers['Vary'],
        );
    }

    public function testPreflightVaryAppendsExistingVaryFromRequest(): void
    {
        $middleware = new CorsMiddleware(origins: ['https://app.example.com']);
        $request = new Request(
            'OPTIONS',
            '/api/v1/users',
            '',
            [
                'origin' => 'https://app.example.com',
                'access-control-request-method' => 'POST',
                'vary' => 'Accept-Encoding',
            ],
        );

        $response = $middleware->process($request, static function (): Response {
            self::fail('Preflight must short-circuit');
        });

        $vary = $response->headers['Vary'];
        $tokens = array_map('trim', explode(',', $vary));
        self::assertContains('Origin', $tokens);
        self::assertContains('Access-Control-Request-Method', $tokens);
        self::assertContains('Access-Control-Request-Headers', $tokens);
        self::assertContains('Accept-Encoding', $tokens);
    }

    public function testPreflightEchoesIntersectionOfRequestedAndConfiguredHeaders(): void
    {
        $middleware = new CorsMiddleware(
            origins: ['https://app.example.com'],
            headers: ['Content-Type', 'X-Other'],
        );
        $request = new Request(
            'OPTIONS',
            '/api/v1/users',
            '',
            [
                'origin' => 'https://app.example.com',
                'access-control-request-method' => 'POST',
                'access-control-request-headers' => 'Content-Type, X-Custom',
            ],
        );

        $response = $middleware->process($request, static function (): Response {
            self::fail('Preflight must short-circuit');
        });

        self::assertSame('Content-Type', $response->headers['Access-Control-Allow-Headers']);
    }

    public function testPreflightFallsBackToConfiguredAllowlistWhenIntersectionEmpty(): void
    {
        $middleware = new CorsMiddleware(
            origins: ['https://app.example.com'],
            headers: ['Content-Type', 'Authorization'],
        );
        $request = new Request(
            'OPTIONS',
            '/api/v1/users',
            '',
            [
                'origin' => 'https://app.example.com',
                'access-control-request-method' => 'POST',
                'access-control-request-headers' => 'X-Custom, X-Other',
            ],
        );

        $response = $middleware->process($request, static function (): Response {
            self::fail('Preflight must short-circuit');
        });

        self::assertSame('Content-Type, Authorization', $response->headers['Access-Control-Allow-Headers']);
    }

    public function testPreflightWithEmptyConfiguredAllowlistEchoesRequestedHeadersAsIs(): void
    {
        $middleware = new CorsMiddleware(
            origins: ['https://app.example.com'],
            headers: [],
        );
        $request = new Request(
            'OPTIONS',
            '/api/v1/users',
            '',
            [
                'origin' => 'https://app.example.com',
                'access-control-request-method' => 'POST',
                'access-control-request-headers' => 'Content-Type, X-Custom',
            ],
        );

        $response = $middleware->process($request, static function (): Response {
            self::fail('Preflight must short-circuit');
        });

        self::assertSame('Content-Type, X-Custom', $response->headers['Access-Control-Allow-Headers']);
    }

    public function testPreflightWithoutAccessControlRequestHeadersEchoesConfiguredAllowlist(): void
    {
        $middleware = new CorsMiddleware(
            origins: ['https://app.example.com'],
            headers: ['Content-Type', 'Authorization', 'X-CSRF-Token'],
        );
        $request = new Request(
            'OPTIONS',
            '/api/v1/users',
            '',
            [
                'origin' => 'https://app.example.com',
                'access-control-request-method' => 'POST',
            ],
        );

        $response = $middleware->process($request, static function (): Response {
            self::fail('Preflight must short-circuit');
        });

        self::assertSame(
            'Content-Type, Authorization, X-CSRF-Token',
            $response->headers['Access-Control-Allow-Headers'],
        );
    }

    public function testPreflightFromNonWhitelistedOriginIncludesVaryHeader(): void
    {
        $middleware = new CorsMiddleware(origins: ['https://app.example.com']);
        $request = new Request(
            'OPTIONS',
            '/api/v1/users',
            '',
            [
                'origin' => 'https://evil.example.com',
                'access-control-request-method' => 'POST',
            ],
        );

        $response = $middleware->process($request, static function (): Response {
            self::fail('Preflight should short-circuit, handler must not be called');
        });

        self::assertSame(403, $response->status);
        self::assertSame(
            'Origin, Access-Control-Request-Method, Access-Control-Request-Headers',
            $response->headers['Vary'],
        );
    }

    public function testPreflightFromNonWhitelistedOriginAppendsExistingRequestVary(): void
    {
        $middleware = new CorsMiddleware(origins: ['https://app.example.com']);
        $request = new Request(
            'OPTIONS',
            '/api/v1/users',
            '',
            [
                'origin' => 'https://evil.example.com',
                'access-control-request-method' => 'POST',
                'vary' => 'Accept-Encoding',
            ],
        );

        $response = $middleware->process($request, static function (): Response {
            self::fail('Preflight should short-circuit, handler must not be called');
        });

        $tokens = array_map('trim', explode(',', $response->headers['Vary']));
        self::assertContains('Origin', $tokens);
        self::assertContains('Access-Control-Request-Method', $tokens);
        self::assertContains('Access-Control-Request-Headers', $tokens);
        self::assertContains('Accept-Encoding', $tokens);
    }

    public function testPreflightVaryOn403MatchesPreflightVaryOn204(): void
    {
        $whitelist = ['https://app.example.com'];
        $headers = [
            'origin' => 'https://app.example.com',
            'access-control-request-method' => 'POST',
        ];
        $evilHeaders = [
            'origin' => 'https://evil.example.com',
            'access-control-request-method' => 'POST',
        ];

        $allowed = (new CorsMiddleware(origins: $whitelist))->process(
            new Request('OPTIONS', '/x', '', $headers),
            static function (): Response {
                self::fail('Preflight must short-circuit');
            },
        );
        $denied = (new CorsMiddleware(origins: $whitelist))->process(
            new Request('OPTIONS', '/x', '', $evilHeaders),
            static function (): Response {
                self::fail('Preflight must short-circuit');
            },
        );

        self::assertSame(204, $allowed->status);
        self::assertSame(403, $denied->status);
        self::assertSame($allowed->headers['Vary'], $denied->headers['Vary']);
    }
}
