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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CsrfMiddleware::class)]
final class CsrfMiddlewareTest extends TestCase
{
    private SignedCookieJar $jar;
    private CsrfMiddleware $middleware;

    protected function setUp(): void
    {
        $this->jar = new SignedCookieJar('test-secret-key');
        $this->middleware = new CsrfMiddleware($this->jar);
    }

    public function testConstants(): void
    {
        self::assertSame('csrf_token', CsrfMiddleware::COOKIE_NAME);
        self::assertSame('X-CSRF-Token', CsrfMiddleware::HEADER_NAME);
        self::assertSame('_token', CsrfMiddleware::FORM_FIELD);
    }

    public function testGetRequestGeneratesTokenAndSetsCookie(): void
    {
        $request = new Request('GET', '/page');

        $capturedRequest = null;
        $response = $this->middleware->process($request, function (Request $r) use (&$capturedRequest): Response {
            $capturedRequest = $r;
            return Response::empty(200);
        });

        self::assertInstanceOf(Request::class, $capturedRequest);
        self::assertNotNull($capturedRequest->csrfToken());
        self::assertSame(64, strlen($capturedRequest->csrfToken()), 'Token must be 64 hex chars (32 bytes)');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $capturedRequest->csrfToken());
    }

    public function testExemptPathSkipsCsrfEntirelyForUnsafeMethods(): void
    {
        $middleware = new CsrfMiddleware($this->jar, ['/api/']);
        $request = new Request('POST', '/api/v1/echo', '', [], '{"x":1}');

        $capturedRequest = null;
        $response = $middleware->process($request, function (Request $r) use (&$capturedRequest): Response {
            $capturedRequest = $r;
            return Response::json(['ok' => true]);
        });

        self::assertSame(200, $response->status);
        self::assertCount(0, $response->cookies(), 'Exempt path must not set a csrf cookie');
        self::assertInstanceOf(Request::class, $capturedRequest);
        self::assertNull($capturedRequest->csrfToken(), 'Exempt path must not populate csrfToken');
    }

    public function testExemptPathSkipsCsrfForSafeMethodsAndDoesNotGenerateCookie(): void
    {
        $middleware = new CsrfMiddleware($this->jar, ['/api/']);
        $request = new Request('GET', '/api/v1/users');

        $response = $middleware->process($request, static fn(): Response => Response::json(['users' => []]));

        self::assertCount(0, $response->cookies(), 'Exempt path must not generate a csrf cookie on safe methods either');
    }

    public function testNonExemptPathStillEnforcesCsrfWhenExemptListConfigured(): void
    {
        $middleware = new CsrfMiddleware($this->jar, ['/api/']);
        $request = new Request('POST', '/form/submit', '', [], 'name=Alice');

        $this->expectException(BadRequestHttpException::class);
        $middleware->process($request, static fn(): Response => Response::json(['ok' => true]));
    }

    public function testTrailingSlashPrefixMatchesSubpathsButNotLookalikes(): void
    {
        $middleware = new CsrfMiddleware($this->jar, ['/api/']);
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

        new CsrfMiddleware($this->jar, ['/api']);
    }

    public function testEmptyPrefixExemptionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("exemptPrefixes");

        new CsrfMiddleware($this->jar, ['']);
    }

    public function testEmptyExactPathExemptionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("exemptPaths");

        new CsrfMiddleware($this->jar, [], ['']);
    }

    public function testExactPathExemptionMatchesOnlyThatPath(): void
    {
        $middleware = new CsrfMiddleware($this->jar, [], ['/health']);
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
        $middleware = new CsrfMiddleware($this->jar);

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
            new CsrfMiddleware($this->jar, ['/']);
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

        new CsrfMiddleware($this->jar, ['/'], ['/health']);
    }

    public function testRootPrefixExemptionIsRejectedWithOmittedJarExemptPaths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("CsrfMiddleware exemptPrefixes cannot be just ['/']");

        new CsrfMiddleware($this->jar, ['/'], []);
    }

    public function testNoArgsConstructionIsAllowedAndEnforcesCsrfGlobally(): void
    {
        $middleware = new CsrfMiddleware($this->jar);

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
        $middleware = new CsrfMiddleware($this->jar, ['/api/']);
        $next = static fn(): Response => Response::json(['ok' => true]);

        self::assertSame(200, $middleware->process(new Request('POST', '/api/users'), $next)->status);
    }

    public function testBootFailsBeforeServingTrafficWhenRootPrefixConfigured(): void
    {
        $threw = false;
        try {
            new CsrfMiddleware($this->jar, ['/']);
        } catch (\InvalidArgumentException $e) {
            $threw = true;
            self::assertStringContainsString("['/']", $e->getMessage());
            self::assertStringContainsString("Use exemptPrefixes", $e->getMessage());
        }
        self::assertTrue($threw, 'Constructor must throw at boot — CSRF must never come up globally disabled');
    }

    public function testMixedExactAndPrefixExemptions(): void
    {
        $middleware = new CsrfMiddleware($this->jar, ['/api/'], ['/health']);
        $next = static fn(): Response => Response::json(['ok' => true]);

        self::assertSame(200, $middleware->process(new Request('POST', '/health'), $next)->status);
        self::assertSame(200, $middleware->process(new Request('POST', '/api/users'), $next)->status);
        self::assertSame(200, $middleware->process(new Request('POST', '/api/'), $next)->status);
    }

    public function testGetRequestWithExistingCookieDoesNotRegenerate(): void
    {
        $token = 'existing-csrf-token-64-chars-padding-padding-padding-padding-padd';
        $signed = $this->jar->sign($token);
        $request = new Request('GET', '/form', '', [], '', null, null, null, [
            CsrfMiddleware::COOKIE_NAME => $signed,
        ]);

        $capturedRequest = null;
        $response = $this->middleware->process($request, function (Request $r) use (&$capturedRequest): Response {
            $capturedRequest = $r;
            return Response::text('ok');
        });

        self::assertInstanceOf(Request::class, $capturedRequest);
        self::assertSame($token, $capturedRequest->csrfToken());
        self::assertCount(0, $response->cookies(), 'No new cookie when existing one is valid');
    }

    public function testGetRequestWithInvalidSignatureClearsCookieAndDoesNotMintNew(): void
    {
        $request = new Request('GET', '/form', '', [], '', null, null, null, [
            CsrfMiddleware::COOKIE_NAME => 'garbage.invalidsig',
        ]);

        $capturedRequest = null;
        $response = $this->middleware->process($request, function (Request $r) use (&$capturedRequest): Response {
            $capturedRequest = $r;
            return Response::text('ok');
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
        $request = new Request('GET', '/form', '', [], '', null, null, null, [
            CsrfMiddleware::COOKIE_NAME => '',
        ]);

        $capturedRequest = null;
        $response = $this->middleware->process($request, function (Request $r) use (&$capturedRequest): Response {
            $capturedRequest = $r;
            return Response::text('ok');
        });

        self::assertInstanceOf(Request::class, $capturedRequest);
        self::assertNotNull($capturedRequest->csrfToken());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $capturedRequest->csrfToken());
        self::assertCount(1, $response->cookies(), 'Empty cookie value is treated as absent → a fresh token is minted');
    }

    public function testGetRequestWithoutAnyCookieMintsNewToken(): void
    {
        $request = new Request('GET', '/form');

        $capturedRequest = null;
        $response = $this->middleware->process($request, function (Request $r) use (&$capturedRequest): Response {
            $capturedRequest = $r;
            return Response::text('ok');
        });

        self::assertInstanceOf(Request::class, $capturedRequest);
        self::assertNotNull($capturedRequest->csrfToken());
        self::assertCount(1, $response->cookies(), 'Absent cookie must mint a fresh token (regression: existing happy path)');
    }

    public function testGetRequestWithValidSignedCookieReusesToken(): void
    {
        $token = 'reused-csrf-token-64-chars-padding-padding-padding-padding-pad';
        $signed = $this->jar->sign($token);
        $request = new Request('GET', '/form', '', [], '', null, null, null, [
            CsrfMiddleware::COOKIE_NAME => $signed,
        ]);

        $capturedRequest = null;
        $response = $this->middleware->process($request, function (Request $r) use (&$capturedRequest): Response {
            $capturedRequest = $r;
            return Response::text('ok');
        });

        self::assertInstanceOf(Request::class, $capturedRequest);
        self::assertSame($token, $capturedRequest->csrfToken());
        self::assertCount(0, $response->cookies(), 'Valid existing token must be reused, not re-minted');
    }

    public function testOptionsPreflightGeneratesCsrfCookieButDoesNotValidate(): void
    {
        $request = new Request('OPTIONS', '/api/v1/users', '', [
            'origin' => 'http://localhost:3000',
            'access-control-request-method' => 'POST',
        ]);

        $capturedRequest = null;
        $response = $this->middleware->process($request, function (Request $r) use (&$capturedRequest): Response {
            $capturedRequest = $r;
            return Response::empty(204);
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
        $response = $this->withRemoteAddr('127.0.0.1', function (): Response {
            $middleware = new CsrfMiddleware($this->jar, trustedProxies: ['127.0.0.1']);
            $request = new Request('GET', '/form', '', ['x-forwarded-proto' => 'https']);
            return $middleware->process($request, static fn(): Response => Response::text('ok'));
        });

        $cookies = $response->cookies();
        self::assertCount(1, $cookies);
        self::assertTrue($cookies[0]->secure, 'HTTPS request from trusted proxy → secure=true on cookie');
    }

    public function testGetCookieHasNoSecureFlagWhenForwardedProtoComesFromUntrustedIp(): void
    {
        $response = $this->withRemoteAddr('198.51.100.5', function (): Response {
            $middleware = new CsrfMiddleware($this->jar, trustedProxies: ['127.0.0.1']);
            $request = new Request('GET', '/form', '', ['x-forwarded-proto' => 'https']);
            return $middleware->process($request, static fn(): Response => Response::text('ok'));
        });

        $cookies = $response->cookies();
        self::assertCount(1, $cookies);
        self::assertFalse(
            $cookies[0]->secure,
            'csrf_token cookie must NOT set Secure when X-Forwarded-Proto comes from an IP outside the trust list',
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
        $middleware = new CsrfMiddleware($this->jar, [], [], $logger);

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
        $middleware = new CsrfMiddleware($this->jar, [], [], $logger);

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
