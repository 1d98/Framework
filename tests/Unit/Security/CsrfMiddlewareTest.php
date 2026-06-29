<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Security;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Response\ResponseInterface;
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
        $response = $this->processAsSecure(function () use (&$capturedRequest): ResponseInterface {
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

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->status);
        self::assertCount(0, $response->cookies, 'Exempt path must not set a csrf cookie');
        self::assertInstanceOf(Request::class, $capturedRequest);
        self::assertNull($capturedRequest->csrfToken(), 'Exempt path must not populate csrfToken');
    }

    public function testExemptPathSkipsCsrfForSafeMethodsAndDoesNotGenerateCookie(): void
    {
        $middleware = new CsrfMiddleware($this->jar, exemptPrefixes: ['/api/'], trustedProxies: [self::TRUSTED_REMOTE_ADDR]);

        $response = $this->processAsSecure(function () use ($middleware): ResponseInterface {
            return $middleware->process(
                new Request('GET', '/api/v1/users', headers: ['x-forwarded-proto' => 'https']),
                static fn(): Response => Response::json(['users' => []]),
            );
        });

        self::assertInstanceOf(Response::class, $response);
        self::assertCount(0, $response->cookies, 'Exempt path must not generate a csrf cookie on safe methods either');
    }

    public function testNonExemptPathStillEnforcesCsrfWhenExemptListConfigured(): void
    {
        $middleware = new CsrfMiddleware($this->jar, exemptPrefixes: ['/api/'], trustedProxies: [self::TRUSTED_REMOTE_ADDR]);
        $token = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($token));
        $request = new Request('POST', '/form/submit', '', [], 'name=Alice', null, null, null, [CsrfMiddleware::COOKIE_NAME => $signed]);

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

        // 64-char hex token — required because the cookie payload format
        // now embeds the token in `1:<token>:<issuedAt>` and the
        // middleware rejects anything that is not valid lowercase hex.
        $token = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($token));
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

        $token = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($token));
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
        // 64 lowercase hex chars — the bare token the middleware extracts
        // from the v1 payload (`1:<token>:<issuedAt>`). Anything outside
        // this alphabet (e.g. the old `-`-padded placeholder) is rejected
        // as `token malformed` by parseAndValidate() and the cookie is
        // rotated to a freshly-minted one instead of being reused.
        $token = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($token));

        $capturedRequest = null;
        $response = $this->processAsSecure(function () use ($signed, &$capturedRequest): ResponseInterface {
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
        self::assertInstanceOf(Response::class, $response);
        self::assertCount(0, $response->cookies, 'No new cookie when existing one is valid');
    }

    public function testGetRequestWithInvalidSignatureClearsCookieAndDoesNotMintNew(): void
    {
        $capturedRequest = null;
        $response = $this->processAsSecure(function () use (&$capturedRequest): ResponseInterface {
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

        self::assertInstanceOf(Response::class, $response);
        self::assertCount(0, $response->cookies, 'No new csrf cookie must be minted on signature failure (fixation guard)');

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
        $response = $this->processAsSecure(function () use (&$capturedRequest): ResponseInterface {
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
        self::assertInstanceOf(Response::class, $response);
        self::assertCount(1, $response->cookies, 'Empty cookie value is treated as absent → a fresh token is minted');
    }

    public function testGetRequestWithoutAnyCookieMintsNewToken(): void
    {
        $capturedRequest = null;
        $response = $this->processAsSecure(function () use (&$capturedRequest): ResponseInterface {
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
        self::assertInstanceOf(Response::class, $response);
        self::assertCount(1, $response->cookies, 'Absent cookie must mint a fresh token (regression: existing happy path)');
    }

    public function testGetRequestWithValidSignedCookieReusesToken(): void
    {
        $token = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($token));

        $capturedRequest = null;
        $response = $this->processAsSecure(function () use ($signed, &$capturedRequest): ResponseInterface {
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
        self::assertInstanceOf(Response::class, $response);
        self::assertCount(0, $response->cookies, 'Valid existing token must be reused, not re-minted');
    }

    public function testOptionsPreflightGeneratesCsrfCookieButDoesNotValidate(): void
    {
        $capturedRequest = null;
        $response = $this->processAsSecure(function () use (&$capturedRequest): ResponseInterface {
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
        self::assertInstanceOf(Response::class, $response);
        $cookies = $response->cookies;
        self::assertCount(1, $cookies);
        self::assertSame(CsrfMiddleware::COOKIE_NAME, $cookies[0]->name);
    }

    public function testPostWithValidTokenInHeaderPasses(): void
    {
        $token = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($token));
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
        $token = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($token));
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
        $token = bin2hex(random_bytes(32));
        $formToken = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($token));
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
        $expected = bin2hex(random_bytes(32));
        $provided = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($expected));
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
        // Cookie is a *valid* v1 payload so the middleware reaches the
        // request-side check; the request has no header and no form token,
        // so `token not in request` is the canonical failure message.
        $token = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($token));
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
        $token = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($token));
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
        $token = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($token));

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

        $response = $this->processAsSecure(function () use ($middleware): ResponseInterface {
            return $middleware->process(
                new Request('GET', '/form', headers: ['x-forwarded-proto' => 'https']),
                static fn(): Response => Response::text('ok'),
            );
        });

        self::assertInstanceOf(Response::class, $response);
        $cookies = $response->cookies;
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
            $this->withRemoteAddr('198.51.100.5', function () use ($middleware): ResponseInterface {
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
        // The cookie must be a *valid* v1 payload so the middleware
        // reaches the form-field-array check. A malformed cookie would
        // short-circuit at parseAndValidate() with `token malformed`
        // instead of the actionable `_token form field must be a scalar`
        // message this test is exercising.
        $token = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($token));
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
        $token = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($token));
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
        $token = bin2hex(random_bytes(32));
        $formToken = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($token));

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
        $token = bin2hex(random_bytes(32));
        $formToken = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($token));

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
        $token = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($token));

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

    // -------------------------------------------------------------------
    // TTL behavior (cookie payload version + expiry) — Phase 1 regression.
    // -------------------------------------------------------------------

    public function testValidV1TokenWithinTtlIsAccepted(): void
    {
        // 1-minute-old v1 payload is comfortably within the 1h default ttl.
        $token = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($token, time() - 60));
        $request = new Request(
            'POST',
            '/submit',
            '',
            ['x-csrf-token' => $token],
            '',
            null,
            null,
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        $response = $this->middleware->process($request, static fn(): Response => Response::json(['ok' => true]));
        self::assertSame(200, $response->status);
    }

    public function testExpiredV1TokenIsRejected(): void
    {
        // 2-hour-old v1 payload is past the 1h default ttl. The middleware
        // must reject with `token expired`, NOT `token malformed` — the
        // format is correct; the issue is age.
        $token = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($token, time() - 7200));
        $request = new Request(
            'POST',
            '/submit',
            '',
            ['x-csrf-token' => $token],
            '',
            null,
            null,
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('CSRF token mismatch: token expired');
        $this->middleware->process($request, static fn(): Response => Response::json([]));
    }

    public function testGetRequestWithExpiredV1CookieMintsFreshOnHttps(): void
    {
        // Companion to {@see self::testExpiredV1TokenIsRejected()}. There we
        // prove that an UNSAFE (POST) request with an expired-but-signed v1
        // cookie is rejected as `token expired`. Here we pin the GET path,
        // which is rotation, not rejection: the cookie is expired (signature
        // fine, TTL elapsed), so we mint a FRESH token and emit it via
        // Set-Cookie so the user's next unsafe request has a valid one. The
        // fresh token must be DISTINCT from the stale one — this is the
        // cookie-fixation guard, and it differs from the signature-failure
        // case in handleSafe() which CLEARS the cookie instead of minting.
        //
        // Plumbing: HTTPS is simulated via the trusted-proxy + X-Forwarded-Proto
        // header combo (matches the production deployment behind a TLS
        // terminator). Without this, `mintFreshCookie()` would throw the
        // LogicException-pinning path — which is correct (`__Host-` requires
        // Secure) but not what this test is about.
        $expiredToken = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($expiredToken, time() - 7200));

        $capturedRequest = null;
        $response = $this->processAsSecure(function () use ($signed, &$capturedRequest): ResponseInterface {
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
        // csrfToken() is statically `?string`; the not-null + regex below narrow
        // it to a plain lower-case hex string, which is the contract of
        // mintFreshCookie() (32 random bytes → 64 hex chars).
        $freshToken = $capturedRequest->csrfToken();
        self::assertNotNull($freshToken, 'Rotation must expose a fresh token to the handler');
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $freshToken,
            'Fresh token must be 64 lowercase hex chars (matches mintFreshCookie contract)',
        );
        self::assertNotSame(
            $expiredToken,
            $freshToken,
            'Fresh token MUST differ from the expired one — reusing the expired token '
            . 'would defeat TTL enforcement on the next unsafe request',
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->status);
        self::assertCount(
            1,
            $response->cookies,
            'Rotation must emit exactly one fresh `__Host-csrf_token` Set-Cookie '
            . '(distinct from the signature-failure case which CLEARS)',
        );
        self::assertSame(CsrfMiddleware::COOKIE_NAME, $response->cookies[0]->name);
    }

    public function testMalformedPayloadIsRejected(): void
    {
        // Neither a valid v1 (`1:<token>:<issuedAt>`) nor a valid v0
        // (64 lowercase hex chars). The cookie is properly signed
        // (`garbage`) so the signature check passes; parseAndValidate
        // must surface the structural failure as `token malformed`.
        $signed = $this->jar->sign('not-a-valid-csrf-payload');
        $request = new Request(
            'POST',
            '/submit',
            '',
            ['x-csrf-token' => 'some-form-token'],
            '',
            null,
            null,
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('CSRF token mismatch: token malformed');
        $this->middleware->process($request, static fn(): Response => Response::json([]));
    }

    public function testV0LegacyTokenIsAcceptedDuringGracePeriod(): void
    {
        // Legacy v0: bare 64-char hex with NO version prefix and NO
        // timestamp. The middleware's static v0-cutoff is initialised
        // lazily on the first v0 observation to `time() + graceTtl`
        // (default 7 days), so any legacy token seen during this run
        // is within the grace window.
        //
        // We can't share the `$v0CutoffTimestamp` static across runs
        // (PHPUnit spawns a fresh PHP process per test class), but we
        // CAN rely on its lazy initialisation on first sight. After
        // the very first v0 token, the cutoff is locked in for the
        // remaining tests in this class.
        $token = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'; // 64 × 'a'
        $signed = $this->jar->sign($token);
        $request = new Request(
            'POST',
            '/submit',
            '',
            ['x-csrf-token' => $token],
            '',
            null,
            null,
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        $response = $this->middleware->process($request, static fn(): Response => Response::json(['ok' => true]));
        self::assertSame(200, $response->status);
    }

    public function testZeroTtlDisablesTtlEnforcement(): void
    {
        // Pins the docblock claim that `ttl=0` disables TTL enforcement:
        // any v1 payload — no matter how old — must be accepted when the
        // operator opts out of the age cap. See parseAndValidate() at
        // src/Security/CsrfMiddleware.php:419-424 — the `$age > $ttl`
        // branch is gated on `$this->ttl > 0`, so `ttl=0` skips it. The
        // 2-hour age is well past the 1h default and chosen so the test
        // fails loudly if anyone re-introduces the `age > 0` regression.
        //
        // Previously named `testZeroTtlRejectsAnyNonFreshV1Token`, which
        // pinned a known-bug behaviour where `ttl=0` collapsed the
        // comparison to `age > 0` (Unix-second resolution ⇒ reject any
        // token ≥ 1s old). The bug was fixed in 0.7.2 to match the
        // docblock; this test now pins the corrected behaviour.
        $middleware = $this->makeMiddlewareWithTtl(0, graceTtl: 0);
        $token = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($token, time() - 7200));
        $request = new Request(
            'POST',
            '/submit',
            '',
            ['x-csrf-token' => $token],
            '',
            null,
            null,
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        $captured = null;
        $response = $middleware->process(
            $request,
            function (Request $r) use (&$captured): Response {
                $captured = $r;
                return Response::text('ok');
            },
        );

        self::assertSame(200, $response->status, 'Stale token must NOT be rejected when ttl=0');
        self::assertInstanceOf(Request::class, $captured, 'Handler must run — middleware must not short-circuit on age');
        self::assertSame(
            $token,
            $captured->csrfToken(),
            'Handler must see the bare 64-char hex token extracted from the v1 cookie payload',
        );
    }

    public function testZeroTtlDoesNotMaskClockSkewGuard(): void
    {
        // Companion to {@see self::testZeroTtlDisablesTtlEnforcement()}.
        // The clock-skew check at src/Security/CsrfMiddleware.php:419-420
        // (`if ($age < 0)`) is INTENTIONALLY independent of the `$ttl > 0`
        // gate on line 422 — a token stamped in the future is ALWAYS
        // rejected, even when the operator has disabled the TTL cap.
        // This pins the docblock's "Tokens stamped in the future (clock
        // skew) are ALWAYS rejected" claim so a future refactor can't
        // accidentally fold the two branches together.
        $middleware = $this->makeMiddlewareWithTtl(0, graceTtl: 0);
        $token = bin2hex(random_bytes(32));
        // 60 seconds ahead — large enough to survive a slow test runner
        // (we're not racing the wall clock on the negative direction).
        $signed = $this->jar->sign($this->v1Payload($token, time() + 60));
        $request = new Request(
            'POST',
            '/submit',
            '',
            ['x-csrf-token' => $token],
            '',
            null,
            null,
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('CSRF token mismatch: token expired');
        $middleware->process($request, static fn(): Response => Response::json([]));
    }

    /**
     * Direct exercise of the `graceTtl=0` cut-over would require the
     * static `$v0CutoffTimestamp` to be in the past, but Unix-second
     * resolution makes the `time() > cutoff` check racy at sub-second
     * latency. Instead we verify the deterministic property: the
     * middleware initialises `$v0CutoffTimestamp = time() + graceTtl`
     * on the first v0 sight, and the operator-facing knob is wired
     * through to the storage layer.
     *
     * We can't unit-test the rejection path *behavior* of `graceTtl=0`
     * without a 1-second `sleep()` and a shared-process static state
     * that may already be locked by another test. The reflection-based
     * check pins the implementation surface; the runtime behaviour is
     * covered by {@see self::testV0LegacyTokenIsAcceptedDuringGracePeriod()}
     * exercising the default (7-day) grace window.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testZeroGraceTtlInitialisesCutoffToNowInFreshProcess(): void
    {
        // Fresh worker → `$v0CutoffTimestamp` starts at `null`.
        $middleware = $this->makeMiddlewareWithTtl(3600, graceTtl: 0);

        // Before any v0 token is seen, the cutoff is null.
        $cutoffBefore = $this->readV0Cutoff();
        self::assertNull($cutoffBefore, 'v0 cutoff must start unset');

        // Observe a v0 token → triggers the `??= time() + graceTtl` branch.
        $token = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc'; // 64 × 'c'
        $signed = $this->jar->sign($token);
        $request = new Request(
            'POST',
            '/submit',
            '',
            ['x-csrf-token' => $token],
            '',
            null,
            null,
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        $before = time();
        $middleware->process($request, static fn(): Response => Response::json(['ok' => true]));
        $after = time();

        $cutoffAfter = $this->readV0Cutoff();
        self::assertNotNull($cutoffAfter, 'cutoff must be set after the first v0 observation');
        // graceTtl=0 ⇒ cutoff is pinned to the `time()` value seen inside
        // parseAndValidate() at first v0 sight. Tolerate off-by-one from
        // the wall clock advancing across the process() call.
        self::assertGreaterThanOrEqual($before, $cutoffAfter);
        self::assertLessThanOrEqual($after, $cutoffAfter);
    }

    private function readV0Cutoff(): ?int
    {
        $ref = new \ReflectionProperty(CsrfMiddleware::class, 'v0CutoffTimestamp');
        /** @var ?int $value */
        $value = $ref->getValue();
        return $value;
    }

    public function testTtlRejectsFutureDatedV1TokenAsExpired(): void
    {
        // Clock skew guard: a v1 payload stamped in the future is
        // rejected as expired (negative age) rather than accepted
        // with an extended TTL window. We use 1 hour ahead — well
        // outside any plausible clock skew between the minter and
        // the validator.
        $token = bin2hex(random_bytes(32));
        $signed = $this->jar->sign($this->v1Payload($token, time() + 3600));
        $request = new Request(
            'POST',
            '/submit',
            '',
            ['x-csrf-token' => $token],
            '',
            null,
            null,
            null,
            [CsrfMiddleware::COOKIE_NAME => $signed],
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('CSRF token mismatch: token expired');
        $this->middleware->process($request, static fn(): Response => Response::json([]));
    }

    // -------------------------------------------------------------------
    // Test helpers.
    // -------------------------------------------------------------------

    /**
     * Mint a v1 cookie payload string:
     *   `1:<bare-hex>:<issuedAt-unix-seconds>`
     *
     * The middleware embeds this string under the HMAC; on a POST, it
     * extracts the bare-hex portion (the `<token>` part) and compares
     * it byte-for-byte against the value the client put in the
     * `X-CSRF-Token` header / `_token` form field. The timestamp
     * lives only on the cookie side — clients never see it.
     *
     * @param string $token    64 lowercase hex chars (32 random bytes)
     * @param ?int   $issuedAt Unix seconds. Defaults to `time()` for "just minted".
     */
    private function v1Payload(string $token, ?int $issuedAt = null): string
    {
        return '1:' . $token . ':' . ($issuedAt ?? time());
    }

    /**
     * Construct a fresh middleware with a custom `ttl` (and optional
     * `graceTtl`). Used by the TTL regression tests that need to
     * toggle the expiry knobs in isolation from the shared
     * `$this->middleware` setup.
     */
    private function makeMiddlewareWithTtl(int $ttl, int $graceTtl = 604800): CsrfMiddleware
    {
        return new CsrfMiddleware(
            $this->jar,
            exemptPrefixes: [],
            exemptPaths: [],
            logger: null,
            trustedProxies: [self::TRUSTED_REMOTE_ADDR],
            ttl: $ttl,
            graceTtl: $graceTtl,
        );
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
