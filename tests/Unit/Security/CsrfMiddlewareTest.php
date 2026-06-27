<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Security;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Logging\LoggerInterface;
use Framework\Security\CsrfMiddleware;
use Framework\Security\SignedCookieJar;
use Framework\Tests\Support\RecordingLogger;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CsrfMiddleware::class)]
final class CsrfMiddlewareTest extends TestCase
{
    private SignedCookieJar $jar;
    private CsrfMiddleware $middleware;

    /** Default REMOTE_ADDR used for the tests; matching the trusted-proxy list. */
    private const string TRUSTED_REMOTE_ADDR = '127.0.0.1';

    protected function setUp(): void
    {
        // 32-character secret — comfortably above the 16-byte minimum.
        $this->jar = new SignedCookieJar('unit-test-secret-32-chars-long');
        // The middleware is constructed with the trusted-proxies list.
        // Every test that mints a cookie goes through {@see self::processAsSecure()},
        // which sets REMOTE_ADDR=127.0.0.1 BEFORE constructing the Request
        // (so RequestHost::snapshotRemoteAddr() captures the trusted address).
        // Without the trusted-proxy address, the new `__Host-` prefix would
        // force every mint test to throw LogicException.
        $this->middleware = new CsrfMiddleware(
            $this->jar,
            exemptPrefixes: [],
            exemptPaths: [],
            logger: null,
            trustedProxies: [self::TRUSTED_REMOTE_ADDR],
        );
    }

    public function testConstants(): void
    {
        // The cookie name MUST be `__Host-csrf_token` — pinning the cookie
        // to Secure + Path=/ + no Domain (RFC 6265bis prefix). A bare
        // `csrf_token` cookie could be shadowed by a subdomain's lax
        // policy and would silently drop over plain HTTP.
        self::assertSame('__Host-csrf_token', CsrfMiddleware::COOKIE_NAME);
        self::assertSame('X-CSRF-Token', CsrfMiddleware::HEADER_NAME);
        self::assertSame('_token', CsrfMiddleware::FORM_FIELD);
    }

    public function testGetRequestGeneratesTokenAndSetsCookie(): void
    {
        $capturedRequest = null;
        $response = $this->processAsSecure(function () use (&$capturedRequest): Response {
            return $this->middleware->process(
                new Request('GET', '/page', headers: ['x-forwarded-proto' => 'https']),
                function (Request $r) use (&$capturedRequest): Response {
                    $capturedRequest = $r;
                    return Response::empty(200);
                },
            );
        });

        self::assertInstanceOf(Request::class, $capturedRequest);
        self::assertNotNull($capturedRequest->csrfToken());
        self::assertSame(64, strlen($capturedRequest->csrfToken()), 'Token must be 64 hex chars (32 bytes)');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $capturedRequest->csrfToken());
    }

    public function testExemptPathSkipsCsrfEntirelyForUnsafeMethods(): void
    {
        $middleware = new CsrfMiddleware($this->jar, exemptPrefixes: ['/api/'], trustedProxies: [self::TRUSTED_REMOTE_ADDR]);

        $capturedRequest = null;
        $response = $middleware->process(
            new Request('POST', '/api/v1/echo', '', [], '{"x":1}'),
            function (Request $r) use (&$capturedRequest): Response {
                $capturedRequest = $r;
                return Response::json(['ok' => true]);
            },
        );

        self::assertSame(200, $response->status);
        self::assertCount(0, $response->cookies(), 'Exempt path must not set a csrf cookie');
        self::assertInstanceOf(Request::class, $capturedRequest);
        self::assertNull($capturedRequest->csrfToken(), 'Exempt path must not populate csrfToken');
    }

    public function testExemptPathSkipsCsrfForSafeMethodsAndDoesNotGenerateCookie(): void
    {
        $middleware = new CsrfMiddleware($this->jar, exemptPrefixes: ['/api/'], trustedProxies: [self::TRUSTED_REMOTE_ADDR]);

        $response = $this->processAsSecure(function () use ($middleware): Response {
            return $middleware->process(
                new Request('GET', '/api/v1/users', headers: ['x-forwarded-proto' => 'https']),
                static fn(): Response => Response::json(['users' => []]),
            );
        });

        self::assertCount(0, $response->cookies(), 'Exempt path must not generate a csrf cookie on safe methods either');
    }

    public function testNonExemptPathStillEnforcesCsrfWhenExemptListConfigured(): void
    {
        $middleware = new CsrfMiddleware($this->jar, exemptPrefixes: ['/api/'], trustedProxies: [self::TRUSTED_REMOTE_ADDR]);
        $request = new Request('POST', '/form/submit', '', [], 'name=Alice');

        $this->expectException(BadRequestHttpException::class);
        $middleware->process($request, static fn(): Response => Response::json(['ok' => true]));
    }

    public function testTrailingSlashPrefixMatchesSubpathsButNotLookalikes(): void
    {
        $middleware = new CsrfMiddleware($this->jar, exemptPrefixes: ['/api/'], trustedProxies: [self::TRUSTED_REMOTE_ADDR]);
        $next = static fn(): Response => Response::json(['ok' => true]);

        self::assertSame(200, $middleware->process(new Request('POST', '/api/users'), $next)->status);
        self::assertSame(200, $middleware->process(new Request('POST', '/api/v1/echo'), $next)->status);
        self::assertSame(200, $middleware->process(new Request('POST', '/api/'), $next)->status);

        foreach (['/apiv1', '/apocalypse', '/apiary'] as $lookalike) {
            $threw = false;
            try {
                $middleware->process(new Request('POST', $lookalike), $next);
            } catch (BadRequestHttpException) {
                $threw = true;
            }
            self::assertTrue($threw, "Lookalike path {$lookalike} must NOT be exempt (must enforce CSRF)");
        }
    }

    public function testPrefixWithoutTrailingSlashIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("/api");
        $this->expectExceptionMessage("exemptPrefixes");

        new CsrfMiddleware($this->jar, exemptPrefixes: ['/api']);
    }

    public function testEmptyPrefixExemptionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("exemptPrefixes");

        new CsrfMiddleware($this->jar, exemptPrefixes: ['']);
    }

    public function testEmptyExactPathExemptionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("exemptPaths");

        new CsrfMiddleware($this->jar, exemptPaths: ['']);
    }

    public function testExactPathExemptionMatchesOnlyThatPath(): void
    {
        $middleware = new CsrfMiddleware($this->jar, exemptPaths: ['/health'], trustedProxies: [self::TRUSTED_REMOTE_ADDR]);
        $next = static fn(): Response => Response::json(['ok' => true]);

        self::assertSame(200, $middleware->process(new Request('POST', '/health'), $next)->status);

        foreach (['/health-check', '/healthy', '/health/v1', '/health/'] as $path) {
            $threw = false;
            try {
                $middleware->process(new Request('POST', $path), $next);
            } catch (BadRequestHttpException) {
                $threw = true;
            }
            self::assertTrue($threw, "Path {$path} should NOT be exempt (must enforce CSRF)");
        }
    }

    public function testEmptyExemptListAppliesCsrfToAllPaths(): void
    {
        $middleware = new CsrfMiddleware($this->jar, trustedProxies: [self::TRUSTED_REMOTE_ADDR]);

        $token = 'token-64-chars-padding-padding-padding-padding-padding-padding';
        $signed = $this->jar->sign($token);
        $request = new Request(
            'POST',
            '/anything/at/all',
            '',
            ['x-csrf-token' => $token],
            '',
            null,
            null,
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        $response = $middleware->process($request, static fn(): Response => Response::json([]));
        self::assertSame(200, $response->status);
    }

    public function testRootPrefixExemptionIsRejectedAsGlobalDisable(): void
    {
        $threw = false;
        try {
            new CsrfMiddleware($this->jar, exemptPrefixes: ['/']);
        } catch (\InvalidArgumentException $e) {
            $threw = true;
            self::assertStringContainsString("CsrfMiddleware exemptPrefixes cannot be just ['/']", $e->getMessage());
            self::assertStringContainsString("disables CSRF for every path", $e->getMessage());
            self::assertStringContainsString("/api/", $e->getMessage());
            self::assertStringContainsString("exemptPrefixes", $e->getMessage());
        }
        self::assertTrue($threw, 'Constructor must throw InvalidArgumentException for exemptPrefixes: ["/"]');
    }

    public function testRootPrefixExemptionIsRejectedEvenWithNonConflictingExactPaths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("CsrfMiddleware exemptPrefixes cannot be just ['/']");

        new CsrfMiddleware($this->jar, exemptPrefixes: ['/'], exemptPaths: ['/health']);
    }

    public function testRootPrefixExemptionIsRejectedWithOmittedJarExemptPaths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("CsrfMiddleware exemptPrefixes cannot be just ['/']");

        new CsrfMiddleware($this->jar, exemptPrefixes: ['/'], exemptPaths: []);
    }

    public function testNoArgsConstructionIsAllowedAndEnforcesCsrfGlobally(): void
    {
        $middleware = new CsrfMiddleware($this->jar, trustedProxies: [self::TRUSTED_REMOTE_ADDR]);

        $token = 'no-args-token-64-chars-padding-padding-padding-padding-padding-x';
        $signed = $this->jar->sign($token);
        $request = new Request(
            'POST',
            '/anything/at/all',
            '',
            ['x-csrf-token' => $token],
            '',
            null,
            null,
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        $response = $middleware->process($request, static fn(): Response => Response::json([]));
        self::assertSame(200, $response->status, 'No-args construction must enforce CSRF on all paths');
    }

    public function testSpecificSinglePrefixConstructionIsAllowed(): void
    {
        $middleware = new CsrfMiddleware($this->jar, exemptPrefixes: ['/api/'], trustedProxies: [self::TRUSTED_REMOTE_ADDR]);
        $next = static fn(): Response => Response::json(['ok' => true]);

        self::assertSame(200, $middleware->process(new Request('POST', '/api/users'), $next)->status);
    }

    public function testBootFailsBeforeServingTrafficWhenRootPrefixConfigured(): void
    {
        $threw = false;
        try {
            new CsrfMiddleware($this->jar, exemptPrefixes: ['/']);
        } catch (\InvalidArgumentException $e) {
            $threw = true;
            self::assertStringContainsString("['/']", $e->getMessage());
            self::assertStringContainsString("Use exemptPrefixes", $e->getMessage());
        }
        self::assertTrue($threw, 'Constructor must throw at boot — CSRF must never come up globally disabled');
    }

    public function testMixedExactAndPrefixExemptions(): void
    {
        $middleware = new CsrfMiddleware(
            $this->jar,
            exemptPrefixes: ['/api/'],
            exemptPaths: ['/health'],
            trustedProxies: [self::TRUSTED_REMOTE_ADDR],
        );
        $next = static fn(): Response => Response::json(['ok' => true]);

        self::assertSame(200, $middleware->process(new Request('POST', '/health'), $next)->status);
        self::assertSame(200, $middleware->process(new Request('POST', '/api/users'), $next)->status);
        self::assertSame(200, $middleware->process(new Request('POST', '/api/'), $next)->status);
    }

    public function testGetRequestWithExistingCookieDoesNotRegenerate(): void
    {
        $token = 'existing-csrf-token-64-chars-padding-padding-padding-padding-padd';
        $signed = $this->jar->sign($token);

        $capturedRequest = null;
        $response = $this->processAsSecure(function () use ($signed, &$capturedRequest): Response {
            return $this->middleware->process(
                new Request('GET', '/form', headers: ['x-forwarded-proto' => 'https'], cookies: [CsrfMiddleware::COOKIE_NAME => $signed]),
                function (Request $r) use (&$capturedRequest): Response {
                    $capturedRequest = $r;
                    return Response::text('ok');
                },
            );
        });

        self::assertInstanceOf(Request::class, $capturedRequest);
        self::assertSame($token, $capturedRequest->csrfToken());
        self::assertCount(0, $response->cookies(), 'No new cookie when existing one is valid');
    }

    public function testGetRequestWithInvalidSignatureClearsCookieAndDoesNotMintNew(): void
    {
        $capturedRequest = null;
        $response = $this->processAsSecure(function () use (&$capturedRequest): Response {
            return $this->middleware->process(
                new Request(
                    'GET',
                    '/form',
                    headers: ['x-forwarded-proto' => 'https'],
                    cookies: [CsrfMiddleware::COOKIE_NAME => 'garbage.invalidsig'],
                ),
                function (Request $r) use (&$capturedRequest): Response {
                    $capturedRequest = $r;
                    return Response::text('ok');
                },
            );
        });

        self::assertCount(0, $response->cookies(), 'No new csrf cookie must be minted on signature failure (fixation guard)');

        $setCookieLines = $response->toHeaderLines();
        $clearingLines = array_values(array_filter(
            $setCookieLines,
            static fn(string $line): bool => str_starts_with($line, 'Set-Cookie: ' . CsrfMiddleware::COOKIE_NAME . '='),
        ));
        self::assertCount(1, $clearingLines, 'Exactly one clearing Set-Cookie for the csrf cookie must be emitted');
        $clearing = $clearingLines[0];
        self::assertStringContainsString('Max-Age=0', $clearing, 'Clearing cookie must carry Max-Age=0 to invalidate the client-side value');
        self::assertStringContainsString('Path=/', $clearing);
        self::assertStringContainsString('HttpOnly', $clearing);
        self::assertStringContainsString('SameSite=Lax', $clearing);

        self::assertInstanceOf(Request::class, $capturedRequest);
        self::assertNull($capturedRequest->csrfToken(), 'No token is exposed to the handler when the cookie signature is invalid');
    }

    public function testGetRequestWithEmptyCookieValueMintsNewToken(): void
    {
        $capturedRequest = null;
        $response = $this->processAsSecure(function () use (&$capturedRequest): Response {
            return $this->middleware->process(
                new Request(
                    'GET',
                    '/form',
                    headers: ['x-forwarded-proto' => 'https'],
                    cookies: [CsrfMiddleware::COOKIE_NAME => ''],
                ),
                function (Request $r) use (&$capturedRequest): Response {
                    $capturedRequest = $r;
                    return Response::text('ok');
                },
            );
        });

        self::assertInstanceOf(Request::class, $capturedRequest);
        self::assertNotNull($capturedRequest->csrfToken());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $capturedRequest->csrfToken());
        self::assertCount(1, $response->cookies(), 'Empty cookie value is treated as absent → a fresh token is minted');
    }

    public function testGetRequestWithoutAnyCookieMintsNewToken(): void
    {
        $capturedRequest = null;
        $response = $this->processAsSecure(function () use (&$capturedRequest): Response {
            return $this->middleware->process(
                new Request('GET', '/form', headers: ['x-forwarded-proto' => 'https']),
                function (Request $r) use (&$capturedRequest): Response {
                    $capturedRequest = $r;
                    return Response::text('ok');
                },
            );
        });

        self::assertInstanceOf(Request::class, $capturedRequest);
        self::assertNotNull($capturedRequest->csrfToken());
        self::assertCount(1, $response->cookies(), 'Absent cookie must mint a fresh token (regression: existing happy path)');
    }

    public function testGetRequestWithValidSignedCookieReusesToken(): void
    {
        $token = 'reused-csrf-token-64-chars-padding-padding-padding-padding-pad';
        $signed = $this->jar->sign($token);

        $capturedRequest = null;
        $response = $this->processAsSecure(function () use ($signed, &$capturedRequest): Response {
            return $this->middleware->process(
                new Request(
                    'GET',
                    '/form',
                    headers: ['x-forwarded-proto' => 'https'],
                    cookies: [CsrfMiddleware::COOKIE_NAME => $signed],
                ),
                function (Request $r) use (&$capturedRequest): Response {
                    $capturedRequest = $r;
                    return Response::text('ok');
                },
            );
        });

        self::assertInstanceOf(Request::class, $capturedRequest);
        self::assertSame($token, $capturedRequest->csrfToken());
        self::assertCount(0, $response->cookies(), 'Valid existing token must be reused, not re-minted');
    }

    public function testOptionsPreflightGeneratesCsrfCookieButDoesNotValidate(): void
    {
        $capturedRequest = null;
        $response = $this->processAsSecure(function () use (&$capturedRequest): Response {
            return $this->middleware->process(
                new Request(
                    'OPTIONS',
                    '/api/v1/users',
                    headers: [
                        'origin' => 'http://localhost:3000',
                        'access-control-request-method' => 'POST',
                        'x-forwarded-proto' => 'https',
                    ],
                ),
                function (Request $r) use (&$capturedRequest): Response {
                    $capturedRequest = $r;
                    return Response::empty(204);
                },
            );
        });

        self::assertInstanceOf(Request::class, $capturedRequest);
        self::assertNotNull($capturedRequest->csrfToken());
        $cookies = $response->cookies();
        self::assertCount(1, $cookies);
        self::assertSame(CsrfMiddleware::COOKIE_NAME, $cookies[0]->name);
    }

    public function testPostWithValidTokenInHeaderPasses(): void
    {
        $token = 'valid-csrf-token-64-chars-padding-padding-padding-padding-paddi';
        $signed = $this->jar->sign($token);
        $request = new Request(
            'POST',
            '/submit',
            '',
            [
                'x-csrf-token' => $token,
            ],
            '',
            null,
            null,
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        $capturedRequest = null;
        $response = $this->middleware->process($request, function (Request $r) use (&$capturedRequest): Response {
            $capturedRequest = $r;
            return Response::json(['ok' => true]);
        });

        self::assertSame(200, $response->status);
        self::assertInstanceOf(Request::class, $capturedRequest);
        self::assertSame($token, $capturedRequest->csrfToken());
    }

    public function testPostWithValidTokenInFormFieldPasses(): void
    {
        $token = 'form-field-token-64-chars-padding-padding-padding-padding-padd';
        $signed = $this->jar->sign($token);
        $request = new Request(
            'POST',
            '/submit',
            '',
            ['content-type' => 'application/x-www-form-urlencoded'],
            '_token=' . $token . '&name=Alice',
            null,
            ['_token' => $token, 'name' => 'Alice'],
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        $capturedRequest = null;
        $response = $this->middleware->process($request, function (Request $r) use (&$capturedRequest): Response {
            $capturedRequest = $r;
            return Response::json(['ok' => true]);
        });

        self::assertSame(200, $response->status);
        self::assertInstanceOf(Request::class, $capturedRequest);
        self::assertSame($token, $capturedRequest->csrfToken());
    }

    public function testHeaderTakesPrecedenceOverFormField(): void
    {
        $token = 'header-token-64-chars-padding-padding-padding-padding-padding';
        $formToken = 'form-token-64-chars-padding-padding-padding-padding-padding-x';
        $signed = $this->jar->sign($token);
        $request = new Request(
            'POST',
            '/submit',
            '',
            [
                'x-csrf-token' => $token,
                'content-type' => 'application/x-www-form-urlencoded',
            ],
            '',
            null,
            ['_token' => $formToken],
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        $capturedRequest = null;
        $response = $this->middleware->process($request, function (Request $r) use (&$capturedRequest): Response {
            $capturedRequest = $r;
            return Response::json(['ok' => true]);
        });

        self::assertSame(200, $response->status);
        self::assertInstanceOf(Request::class, $capturedRequest);
        self::assertSame($token, $capturedRequest->csrfToken());
    }

    public function testPostWithMismatchedTokenThrows(): void
    {
        $expected = 'expected-token-64-chars-padding-padding-padding-padding-padding';
        $provided = 'provided-token-64-chars-padding-padding-padding-padding-padding';
        $signed = $this->jar->sign($expected);
        $request = new Request(
            'POST',
            '/submit',
            '',
            ['x-csrf-token' => $provided],
            '',
            null,
            null,
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('invalid token');

        $this->middleware->process($request, static fn(): Response => Response::json([]));
    }

    public function testPostWithMissingCookieThrows(): void
    {
        $request = new Request(
            'POST',
            '/submit',
            '',
            ['x-csrf-token' => 'some-token'],
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('cookie missing');

        $this->middleware->process($request, static fn(): Response => Response::json([]));
    }

    public function testPostWithTamperedCookieThrows(): void
    {
        $request = new Request(
            'POST',
            '/submit',
            '',
            ['x-csrf-token' => 'some-token'],
            '',
            null,
            null,
            null,
            [CsrfMiddleware::COOKIE_NAME => 'some-value.bad-sig'],
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('cookie missing');

        $this->middleware->process($request, static fn(): Response => Response::json([]));
    }

    public function testPostWithMissingTokenThrows(): void
    {
        $token = 'token-without-match-64-chars-padding-padding-padding-padding-pad';
        $signed = $this->jar->sign($token);
        $request = new Request(
            'POST',
            '/submit',
            '',
            [],
            '',
            null,
            null,
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('token not in request');

        $this->middleware->process($request, static fn(): Response => Response::json([]));
    }

    public function testPostWithEmptyHeaderTokenThrows(): void
    {
        $token = 'token-64-chars-padding-padding-padding-padding-padding-padding';
        $signed = $this->jar->sign($token);
        $request = new Request(
            'POST',
            '/submit',
            '',
            ['x-csrf-token' => ''],
            '',
            null,
            null,
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('token not in request');

        $this->middleware->process($request, static fn(): Response => Response::json([]));
    }

    public function testPutAndPatchAndDeleteBehaveLikePost(): void
    {
        $token = 'token-64-chars-padding-padding-padding-padding-padding-padding';
        $signed = $this->jar->sign($token);

        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $request = new Request(
                $method,
                '/resource/1',
                '',
                ['x-csrf-token' => $token],
                '',
                null,
                null,
                null,
                [CsrfMiddleware::COOKIE_NAME => $signed],
            );

            $capturedRequest = null;
            $response = $this->middleware->process($request, function (Request $r) use (&$capturedRequest): Response {
                $capturedRequest = $r;
                return Response::json(['ok' => true]);
            });

            self::assertSame(200, $response->status, "Method {$method} should pass with valid token");
            self::assertInstanceOf(Request::class, $capturedRequest);
            self::assertSame($token, $capturedRequest->csrfToken());
        }
    }

    public function testGetSetsSecureCookieWhenRequestIsSecure(): void
    {
        $middleware = new CsrfMiddleware($this->jar, trustedProxies: [self::TRUSTED_REMOTE_ADDR]);

        $response = $this->processAsSecure(function () use ($middleware): Response {
            return $middleware->process(
                new Request('GET', '/form', headers: ['x-forwarded-proto' => 'https']),
                static fn(): Response => Response::text('ok'),
            );
        });

        $cookies = $response->cookies();
        self::assertCount(1, $cookies);
        self::assertTrue($cookies[0]->secure, 'HTTPS request from trusted proxy → secure=true on cookie');
    }

    public function testGetCookieHasNoSecureFlagWhenForwardedProtoComesFromUntrustedIp(): void
    {
        $middleware = new CsrfMiddleware($this->jar, trustedProxies: [self::TRUSTED_REMOTE_ADDR]);

        // Remote addr is OUTSIDE the trusted-proxy list — X-Forwarded-Proto
        // is ignored, the connection is treated as plain HTTP, and the
        // `__Host-` prefix refuses to mint a cookie. So the test asserts
        // that minting over an untrusted proxy throws LogicException.
        $threw = false;
        try {
            $this->withRemoteAddr('198.51.100.5', function () use ($middleware): Response {
                return $middleware->process(
                    new Request('GET', '/form', headers: ['x-forwarded-proto' => 'https']),
                    static fn(): Response => Response::text('ok'),
                );
            });
        } catch (LogicException $e) {
            $threw = true;
            self::assertStringContainsString('__Host-csrf_token', $e->getMessage());
            self::assertStringContainsString('insecure connection', $e->getMessage());
        }
        self::assertTrue(
            $threw,
            'csrf_token cookie must NOT be minted when X-Forwarded-Proto comes from an IP outside the trust list',
        );
    }

    public function testMintOverHttpThrowsLogicException(): void
    {
        // The `__Host-` cookie prefix requires the `Secure` flag, which
        // requires HTTPS. When the request is provably plain HTTP (no
        // X-Forwarded-Proto, no trusted-proxy claim), the middleware
        // REFUSES to mint a cookie — every conforming browser would
        // silently drop it anyway, so failing loud at the call site is
        // the only honest behaviour.
        $middleware = new CsrfMiddleware($this->jar); // no trustedProxies

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('__Host-csrf_token');
        $this->expectExceptionMessage('insecure connection');

        $middleware->process(
            new Request('GET', '/form'),
            static fn(): Response => Response::text('ok'),
        );
    }

    public function testPostWithArrayFormTokenThrowsActionable400(): void
    {
        $token = 'valid-csrf-token-64-chars-padding-padding-padding-padding-paddi';
        $signed = $this->jar->sign($token);
        $request = new Request(
            'POST',
            '/submit',
            '',
            ['content-type' => 'application/x-www-form-urlencoded'],
            '_token%5B%5D=a&_token%5B%5D=b',
            null,
            ['_token' => ['a', 'b']],
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        try {
            $this->middleware->process($request, static fn(): Response => Response::json([]));
            self::fail('Expected BadRequestHttpException for array _token form field');
        } catch (BadRequestHttpException $e) {
            self::assertStringContainsString('_token', $e->getMessage());
            self::assertStringContainsString('array', $e->getMessage());
            self::assertStringContainsString('name="_token[]"', $e->getMessage());
            self::assertStringContainsString('hidden input', $e->getMessage());
        }
    }

    public function testPostWithStringFormTokenStillPasses(): void
    {
        $token = 'valid-csrf-token-64-chars-padding-padding-padding-padding-paddi';
        $signed = $this->jar->sign($token);
        $request = new Request(
            'POST',
            '/submit',
            '',
            ['content-type' => 'application/x-www-form-urlencoded'],
            '_token=' . $token,
            null,
            ['_token' => $token],
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        $response = $this->middleware->process($request, static fn(): Response => Response::json(['ok' => true]));
        self::assertSame(200, $response->status);
    }

    public function testPostWithBothHeaderAndFormLogsNoticeAndHeaderWins(): void
    {
        $token = 'valid-csrf-token-64-chars-padding-padding-padding-padding-paddi';
        $formToken = 'form-token-64-chars-padding-padding-padding-padding-padding-x';
        $signed = $this->jar->sign($token);

        $logger = new RecordingLogger();
        $middleware = new CsrfMiddleware($this->jar, [], [], $logger, [self::TRUSTED_REMOTE_ADDR]);

        $request = new Request(
            'POST',
            '/submit',
            '',
            [
                'x-csrf-token' => $token,
                'content-type' => 'application/x-www-form-urlencoded',
            ],
            '_token=' . $formToken,
            null,
            ['_token' => $formToken],
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        $capturedRequest = null;
        $response = $middleware->process($request, function (Request $r) use (&$capturedRequest): Response {
            $capturedRequest = $r;
            return Response::json(['ok' => true]);
        });

        self::assertSame(200, $response->status);
        self::assertInstanceOf(Request::class, $capturedRequest);
        self::assertSame($token, $capturedRequest->csrfToken(), 'Header token must win over form token');
        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertStringContainsString('X-CSRF-Token', $logger->records[0]['message']);
        self::assertStringContainsString('_token', $logger->records[0]['message']);
        self::assertStringContainsString('header takes precedence', $logger->records[0]['message']);
    }

    public function testPostWithBothHeaderAndFormDoesNotLogWhenLoggerIsNull(): void
    {
        $token = 'valid-csrf-token-64-chars-padding-padding-padding-padding-paddi';
        $formToken = 'form-token-64-chars-padding-padding-padding-padding-padding-x';
        $signed = $this->jar->sign($token);

        $request = new Request(
            'POST',
            '/submit',
            '',
            [
                'x-csrf-token' => $token,
                'content-type' => 'application/x-www-form-urlencoded',
            ],
            '_token=' . $formToken,
            null,
            ['_token' => $formToken],
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        $response = $this->middleware->process($request, static fn(): Response => Response::json(['ok' => true]));
        self::assertSame(200, $response->status, 'No logger → silently use header; must not throw');
    }

    public function testPostWithOnlyFormFieldDoesNotLogNotice(): void
    {
        $token = 'valid-csrf-token-64-chars-padding-padding-padding-padding-paddi';
        $signed = $this->jar->sign($token);

        $logger = new RecordingLogger();
        $middleware = new CsrfMiddleware($this->jar, [], [], $logger, [self::TRUSTED_REMOTE_ADDR]);

        $request = new Request(
            'POST',
            '/submit',
            '',
            ['content-type' => 'application/x-www-form-urlencoded'],
            '_token=' . $token,
            null,
            ['_token' => $token],
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        $response = $middleware->process($request, static fn(): Response => Response::json(['ok' => true]));
        self::assertSame(200, $response->status);
        self::assertCount(0, $logger->records, 'Form-only must not emit the dual-source notice');
    }

    /**
     * Run a closure with REMOTE_ADDR set to a trusted address (127.0.0.1)
     * so RequestHost::snapshotRemoteAddr() captures it at construction time.
     * The closure is responsible for constructing the Request and invoking
     * the middleware — the REMOTE_ADDR snapshot only fires on `new Request(...)`.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function processAsSecure(callable $fn): mixed
    {
        return $this->withRemoteAddr(self::TRUSTED_REMOTE_ADDR, $fn);
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