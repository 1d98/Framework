<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Middleware;

use Framework\Http\Middleware\SecurityHeadersMiddleware;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecurityHeadersMiddleware::class)]
final class SecurityHeadersMiddlewareTest extends TestCase
{
    public function testAddsAllDefaultHeadersOnHttpRequest(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = new Request('GET', '/');

        $response = $middleware->process($request, static fn(Request $r): Response => Response::json([]));

        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        self::assertSame('DENY', $response->headers['X-Frame-Options']);
        self::assertSame('strict-origin-when-cross-origin', $response->headers['Referrer-Policy']);

        $csp = $response->headers['Content-Security-Policy'];
        self::assertMatchesRegularExpression(
            "/^default-src 'self'; script-src 'self' 'nonce-[A-Za-z0-9_-]{22}'; style-src 'self' 'nonce-[A-Za-z0-9_-]{22}'\$/",
            $csp,
        );
        preg_match_all("/'nonce-([A-Za-z0-9_-]{22})'/", $csp, $all);
        self::assertCount(2, $all[1]);
        self::assertSame($all[1][0], $all[1][1]);

        self::assertArrayNotHasKey('Strict-Transport-Security', $response->headers);
    }

    public function testAddsHstsOnHttpsRequest(): void
    {
        $response = $this->withRemoteAddr('127.0.0.1', static function (): Response {
            $middleware = new SecurityHeadersMiddleware(trustedProxies: ['127.0.0.1']);
            $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);
            return $middleware->process($request, static fn(Request $r): Response => Response::json([]));
        });

        self::assertSame('max-age=31536000; includeSubDomains', $response->headers['Strict-Transport-Security']);
    }

    public function testOverrideReplacesDefaultValue(): void
    {
        $middleware = new SecurityHeadersMiddleware(headers: [
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "default-src 'self' https://cdn.example.com",
        ]);
        $request = new Request('GET', '/');

        $response = $middleware->process($request, static fn(Request $r): Response => Response::json([]));

        self::assertSame('SAMEORIGIN', $response->headers['X-Frame-Options']);
        self::assertSame("default-src 'self' https://cdn.example.com", $response->headers['Content-Security-Policy']);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
    }

    public function testNullValueDisablesHeader(): void
    {
        $middleware = new SecurityHeadersMiddleware(headers: [
            'X-Frame-Options' => null,
        ]);
        $request = new Request('GET', '/');

        $response = $middleware->process($request, static fn(Request $r): Response => Response::json([]));

        self::assertArrayNotHasKey('X-Frame-Options', $response->headers);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
    }

    public function testDoesNotOverwriteHandlerSetHeader(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = new Request('GET', '/api/special');

        $response = $middleware->process(
            $request,
            static fn(Request $r): Response => Response::json([])
                ->withHeader('Content-Security-Policy', "default-src 'none'"),
        );

        self::assertSame("default-src 'none'", $response->headers['Content-Security-Policy']);
    }

    public function testNullHstsOverridePreventsHstsOnHttps(): void
    {
        $response = $this->withRemoteAddr('127.0.0.1', static function (): Response {
            $middleware = new SecurityHeadersMiddleware(
                headers: ['Strict-Transport-Security' => null],
                trustedProxies: ['127.0.0.1'],
            );
            $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);
            return $middleware->process($request, static fn(Request $r): Response => Response::json([]));
        });

        self::assertArrayNotHasKey('Strict-Transport-Security', $response->headers);
    }

    public function testHstsIsNotEmittedWhenForwardedProtoComesFromUntrustedIp(): void
    {
        $response = $this->withRemoteAddr('198.51.100.5', static function (): Response {
            $middleware = new SecurityHeadersMiddleware(trustedProxies: ['127.0.0.1']);
            $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);
            return $middleware->process($request, static fn(Request $r): Response => Response::json([]));
        });

        self::assertArrayNotHasKey(
            'Strict-Transport-Security',
            $response->headers,
            'HSTS must not be set when X-Forwarded-Proto comes from an IP outside the trust list',
        );
    }

    public function testExplicitHstsOverrideAppliedAsIs(): void
    {
        $middleware = new SecurityHeadersMiddleware(headers: [
            'Strict-Transport-Security' => 'max-age=63072000; preload',
        ]);
        $request = new Request('GET', '/');

        $response = $middleware->process($request, static fn(Request $r): Response => Response::json([]));

        self::assertSame('max-age=63072000; preload', $response->headers['Strict-Transport-Security']);
    }

    public function testHeaderLookupIsCaseInsensitiveForExistingHeader(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = new Request('GET', '/api/special');

        $response = $middleware->process(
            $request,
            static fn(Request $r): Response => Response::json([])
                ->withHeader('content-security-policy', "default-src 'none'"),
        );

        self::assertSame("default-src 'none'", $response->headers['content-security-policy']);
        self::assertArrayNotHasKey('Content-Security-Policy', $response->headers);
    }

    public function testHstsPreloadAppendsPreloadDirective(): void
    {
        $response = $this->withRemoteAddr('127.0.0.1', static function (): Response {
            $middleware = new SecurityHeadersMiddleware(hstsPreload: true, trustedProxies: ['127.0.0.1']);
            $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);
            return $middleware->process($request, static fn(Request $r): Response => Response::json([]));
        });

        self::assertSame(
            'max-age=31536000; includeSubDomains; preload',
            $response->headers['Strict-Transport-Security'],
        );
    }

    public function testHstsMaxAgeNullOmitsHstsHeaderOnHttps(): void
    {
        $middleware = new SecurityHeadersMiddleware(hstsMaxAge: null);
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        $response = $middleware->process($request, static fn(Request $r): Response => Response::json([]));

        self::assertArrayNotHasKey('Strict-Transport-Security', $response->headers);
    }

    public function testHstsIncludeSubdomainsFalseOmitsDirective(): void
    {
        $response = $this->withRemoteAddr('127.0.0.1', static function (): Response {
            $middleware = new SecurityHeadersMiddleware(hstsIncludeSubdomains: false, trustedProxies: ['127.0.0.1']);
            $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);
            return $middleware->process($request, static fn(Request $r): Response => Response::json([]));
        });

        self::assertSame('max-age=31536000', $response->headers['Strict-Transport-Security']);
    }

    public function testHstsPreloadOnly(): void
    {
        $response = $this->withRemoteAddr('127.0.0.1', static function (): Response {
            $middleware = new SecurityHeadersMiddleware(
                hstsIncludeSubdomains: false,
                hstsPreload: true,
                trustedProxies: ['127.0.0.1'],
            );
            $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);
            return $middleware->process($request, static fn(Request $r): Response => Response::json([]));
        });

        self::assertSame('max-age=31536000; preload', $response->headers['Strict-Transport-Security']);
    }

    public function testCspIncludesNonceForScriptAndStyle(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = new Request('GET', '/');

        $response = $middleware->process($request, static fn(Request $r): Response => Response::json([]));

        $csp = $response->headers['Content-Security-Policy'];
        self::assertMatchesRegularExpression(
            "/^default-src 'self'; script-src 'self' 'nonce-[A-Za-z0-9_-]{22}'; style-src 'self' 'nonce-[A-Za-z0-9_-]{22}'\$/",
            $csp,
        );
    }

    public function testCspNonceIsRequestScoped(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = new Request('GET', '/');

        $nonce1 = $middleware->cspNonce($request);
        $request2 = $request->withAttribute(SecurityHeadersMiddleware::ATTR_CSP_NONCE, $nonce1);
        $nonce2 = $middleware->cspNonce($request2);

        self::assertSame($nonce1, $nonce2);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{22}\z/', $nonce1);
    }

    public function testCspNonceDiffersAcrossRequests(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $requestA = new Request('GET', '/a');
        $requestB = new Request('GET', '/b');

        $nonceA = $middleware->cspNonce($requestA);
        $nonceB = $middleware->cspNonce($requestB);

        self::assertNotSame($nonceA, $nonceB);
    }

    public function testCustomCspOverridesDefaultAndSkipsNonce(): void
    {
        $custom = "default-src 'none'; frame-ancestors 'none'";
        $middleware = new SecurityHeadersMiddleware(csp: $custom);
        $request = new Request('GET', '/');

        $response = $middleware->process($request, static fn(Request $r): Response => Response::json([]));

        self::assertSame($custom, $response->headers['Content-Security-Policy']);
        self::assertStringNotContainsString('nonce-', $response->headers['Content-Security-Policy']);
    }

    public function testCspNonceHeaderMatchesCspDirective(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = new Request('GET', '/');

        $response = $middleware->process($request, static fn(Request $r): Response => Response::json([]));

        $nonce = $response->headers['X-CSP-Nonce'];
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{22}\z/', $nonce);
        self::assertStringContainsString("'nonce-{$nonce}'", $response->headers['Content-Security-Policy']);
    }

    public function testCspNonceHeaderNotOverwrittenByHandler(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = new Request('GET', '/');

        $response = $middleware->process(
            $request,
            static fn(Request $r): Response => Response::json([])
                ->withHeader('X-CSP-Nonce', 'handler-set'),
        );

        self::assertSame('handler-set', $response->headers['X-CSP-Nonce']);
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
