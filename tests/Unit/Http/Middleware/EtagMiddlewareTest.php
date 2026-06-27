<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Middleware;

use Framework\Http\Exception\PreconditionFailedHttpException;
use Framework\Http\Middleware\EtagMiddleware;
use Framework\Http\Middleware\EtagPolicy;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Response\ResponseInterface;
use Framework\Http\Response\StreamedResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EtagMiddleware::class)]
#[CoversClass(EtagPolicy::class)]
final class EtagMiddlewareTest extends TestCase
{
    public function testFirstResponseGetsEtagHeader(): void
    {
        $middleware = new EtagMiddleware();
        $request = new Request('GET', '/users/42');

        $response = $middleware->process(
            $request,
            static fn(): Response => Response::text('hello world'),
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertArrayHasKey('ETag', $response->headers);
        self::assertMatchesRegularExpression('/^"[a-f0-9]{32}"$/', $response->headers['ETag']);
    }

    public function testEtagIsStableForIdenticalBody(): void
    {
        $middleware = new EtagMiddleware();

        $r1 = $middleware->process(new Request('GET', '/a'), static fn(): Response => Response::text('same'));
        $r2 = $middleware->process(new Request('GET', '/b'), static fn(): Response => Response::text('same'));

        self::assertInstanceOf(Response::class, $r1);
        self::assertInstanceOf(Response::class, $r2);
        self::assertSame($r1->headers['ETag'], $r2->headers['ETag']);
    }

    public function testEtagChangesWithBody(): void
    {
        $middleware = new EtagMiddleware();

        $r1 = $middleware->process(new Request('GET', '/a'), static fn(): Response => Response::text('one'));
        $r2 = $middleware->process(new Request('GET', '/a'), static fn(): Response => Response::text('two'));

        self::assertInstanceOf(Response::class, $r1);
        self::assertInstanceOf(Response::class, $r2);
        self::assertNotSame($r1->headers['ETag'], $r2->headers['ETag']);
    }

    public function testIfNoneMatchShortCircuitsTo304(): void
    {
        $middleware = new EtagMiddleware();
        $first = $middleware->process(
            new Request('GET', '/users/42'),
            static fn(): Response => Response::text('hello'),
        );
        self::assertInstanceOf(Response::class, $first);
        $etag = $first->headers['ETag'];

        $handlerCalled = false;
        $request = new Request(
            method: 'GET',
            path: '/users/42',
            headers: ['if-none-match' => $etag],
        );
        $response = $middleware->process(
            $request,
            static function () use (&$handlerCalled): Response {
                $handlerCalled = true;
                return Response::text('hello');
            },
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(304, $response->status);
        self::assertSame('', $response->body, 'Body must be empty on 304');
        self::assertSame($etag, $response->headers['ETag']);
        self::assertArrayHasKey('Cache-Control', $response->headers);
        // The handler still runs (we need the body to compute the etag),
        // but the response body is replaced with empty before returning.
        self::assertTrue($handlerCalled, 'Handler must run so the middleware can compute the etag');
    }

    public function testIfNoneMatchNonMatchingReturns200(): void
    {
        $middleware = new EtagMiddleware();

        $request = new Request(
            method: 'GET',
            path: '/users/42',
            headers: ['if-none-match' => '"different-etag"'],
        );
        $response = $middleware->process(
            $request,
            static fn(): Response => Response::text('hello'),
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->status);
        self::assertSame('hello', $response->body);
    }

    public function testIfNoneMatchWildcardReturns304ForAnyResponse(): void
    {
        $middleware = new EtagMiddleware();
        $request = new Request(
            method: 'GET',
            path: '/users/42',
            headers: ['if-none-match' => '*'],
        );
        $response = $middleware->process(
            $request,
            static fn(): Response => Response::text('hello'),
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(304, $response->status);
    }

    public function testIfMatchEnforcedPath412OnMiss(): void
    {
        $middleware = new EtagMiddleware(new EtagPolicy(
            ifMatchPaths: ['/users/42'],
        ));
        $request = new Request(
            method: 'PUT',
            path: '/users/42',
            headers: ['if-match' => '"wrong-etag"'],
        );

        $this->expectException(PreconditionFailedHttpException::class);
        $middleware->process(
            $request,
            static fn(): Response => Response::text('updated'),
        );
    }

    public function testIfMatchEnforcedPathSuccessOnMatch(): void
    {
        $middleware = new EtagMiddleware(new EtagPolicy(
            ifMatchPaths: ['/users/42'],
        ));
        $body = 'updated-content-v1';
        $etag = '"' . hash('xxh128', $body) . '"';

        $putRequest = new Request(
            method: 'PUT',
            path: '/users/42',
            headers: ['if-match' => $etag],
        );
        $response = $middleware->process(
            $putRequest,
            static fn(): Response => Response::text($body),
        );

        self::assertSame(200, $response->status);
    }

    public function testIfMatchNotEnforcedOnUnlistedPath(): void
    {
        $middleware = new EtagMiddleware(new EtagPolicy(
            ifMatchPaths: ['/users/42'],
        ));
        $request = new Request(
            method: 'PUT',
            path: '/orders/99',
            headers: ['if-match' => '"wrong-etag"'],
        );

        // /orders/99 is not in ifMatchPaths, so If-Match is ignored
        $response = $middleware->process(
            $request,
            static fn(): Response => Response::text('updated'),
        );

        self::assertSame(200, $response->status);
    }

    public function testSkipClosureShortCircuitsEtagInstallation(): void
    {
        $middleware = new EtagMiddleware(new EtagPolicy(
            skip: static fn(Request $r): bool => str_starts_with($r->path, '/me/'),
        ));
        $request = new Request('GET', '/me/feed');

        $response = $middleware->process(
            $request,
            static fn(): Response => Response::text('private'),
        );

        self::assertArrayNotHasKey('ETag', $response->headers);
    }

    public function testWeakEtagPrefix(): void
    {
        $middleware = new EtagMiddleware(new EtagPolicy(weak: true));

        $response = $middleware->process(
            new Request('GET', '/x'),
            static fn(): Response => Response::text('hello'),
        );

        self::assertStringStartsWith('W/"', $response->headers['ETag']);
    }

    public function testNonCacheableStatusSkipsEtag(): void
    {
        $middleware = new EtagMiddleware();

        $response = $middleware->process(
            new Request('GET', '/x'),
            static fn(): Response => Response::empty(500),
        );

        self::assertArrayNotHasKey('ETag', $response->headers);
    }

    public function testUnsupportedAlgorithmRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EtagPolicy(algorithm: 'no-such-hash');
    }

    public function testRejectsMd5(): void
    {
        // md5 has been broken since 2004 — we explicitly reject it from
        // the policy allowlist so an etag value cannot accidentally be
        // derived from a hash with known collision attacks.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('md5');
        new EtagPolicy(algorithm: 'md5');
    }

    public function testRejectsSha1(): void
    {
        // sha1 has documented collision attacks (SHAttered, 2017). The
        // etag allowlist is intentionally narrower than `hash_algos()`
        // and excludes sha1.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sha1');
        new EtagPolicy(algorithm: 'sha1');
    }

    public function testPassesStreamedResponseThroughWithoutAddingEtag(): void
    {
        // StreamedResponse bodies are produced at send() time and cannot
        // be hashed for an etag. Pass through unchanged — the caller is
        // responsible for setting their own ETag header on the streaming
        // response if caching semantics are desired.
        $middleware = new EtagMiddleware();
        $streamed = StreamedResponse::sse(static function (): void {});
        $request = new Request('GET', '/events');

        $response = $middleware->process(
            $request,
            static fn(): ResponseInterface => $streamed,
        );

        self::assertSame($streamed, $response, 'StreamedResponse must be passed through unchanged');
        self::assertArrayNotHasKey('ETag', $response->headers, 'EtagMiddleware must not install ETag on StreamedResponse');
    }
}
