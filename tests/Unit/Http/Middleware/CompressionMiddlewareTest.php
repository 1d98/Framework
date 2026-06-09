<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Middleware;

use Framework\Http\Middleware\CompressionMiddleware;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompressionMiddleware::class)]
final class CompressionMiddlewareTest extends TestCase
{
    private CompressionMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new CompressionMiddleware();
    }

    public function testCompressesTextResponseWhenAcceptEncodingGzip(): void
    {
        $body = str_repeat('A', 2048);
        $request = new Request('GET', '/', '', ['accept-encoding' => 'gzip']);

        $response = $this->middleware->process($request, static fn(): Response => Response::text($body));

        self::assertSame(200, $response->status);
        self::assertSame('gzip', $response->headers['Content-Encoding'] ?? null);
        self::assertSame((string) strlen($response->body), $response->headers['Content-Length'] ?? null);
        self::assertSame('Accept-Encoding', $response->headers['Vary'] ?? null);
        self::assertLessThan(strlen($body), strlen($response->body));
        self::assertSame($body, gzdecode($response->body));
    }

    public function testCompressesJsonResponse(): void
    {
        $body = str_repeat('x', 2048);
        $request = new Request('GET', '/json', '', ['accept-encoding' => 'gzip, deflate']);

        $response = $this->middleware->process($request, static fn(): Response => Response::json(['data' => $body]));

        self::assertSame('gzip', $response->headers['Content-Encoding'] ?? null);
        $decoded = gzdecode($response->body);
        self::assertIsString($decoded);
        self::assertSame(['data' => $body], json_decode($decoded, true));
    }

    public function testCompressesProblemJsonResponse(): void
    {
        $body = str_repeat('y', 2048);
        $request = new Request('GET', '/x', '', ['accept-encoding' => 'gzip']);

        $response = $this->middleware->process(
            $request,
            static fn(): Response => Response::json(['data' => $body])->withHeader('Content-Type', 'application/problem+json'),
        );

        self::assertSame('gzip', $response->headers['Content-Encoding'] ?? null);
    }

    public function testSkipsBodyBelowThreshold(): void
    {
        $body = str_repeat('A', 500);
        $request = new Request('GET', '/', '', ['accept-encoding' => 'gzip']);

        $response = $this->middleware->process($request, static fn(): Response => Response::text($body));

        self::assertArrayNotHasKey('Content-Encoding', $response->headers);
        self::assertSame($body, $response->body);
    }

    public function testSkipsWhenNoAcceptEncoding(): void
    {
        $body = str_repeat('A', 2048);
        $request = new Request('GET', '/');

        $response = $this->middleware->process($request, static fn(): Response => Response::text($body));

        self::assertArrayNotHasKey('Content-Encoding', $response->headers);
        self::assertSame($body, $response->body);
    }

    public function testSkipsWhenAcceptEncodingOnlyIdentity(): void
    {
        $body = str_repeat('A', 2048);
        $request = new Request('GET', '/', '', ['accept-encoding' => 'identity']);

        $response = $this->middleware->process($request, static fn(): Response => Response::text($body));

        self::assertArrayNotHasKey('Content-Encoding', $response->headers);
    }

    public function testSkipsWhenResponseAlreadyGzipped(): void
    {
        $preCompressed = gzencode(str_repeat('A', 2048));
        self::assertNotFalse($preCompressed);
        $request = new Request('GET', '/', '', ['accept-encoding' => 'gzip']);

        $response = $this->middleware->process(
            $request,
            static fn(): Response => new Response(200, $preCompressed, [
                'Content-Type' => 'text/plain',
                'Content-Encoding' => 'br',
            ]),
        );

        self::assertSame('br', $response->headers['Content-Encoding'] ?? null);
        self::assertSame($preCompressed, $response->body);
    }

    public function testSkipsForStatusAboveSuccess(): void
    {
        $body = str_repeat('A', 2048);
        $request = new Request('GET', '/', '', ['accept-encoding' => 'gzip']);

        $redirectResponse = $this->middleware->process(
            $request,
            static fn(): Response => Response::text($body)->withStatus(301),
        );
        self::assertArrayNotHasKey('Content-Encoding', $redirectResponse->headers);

        $errorResponse = $this->middleware->process(
            $request,
            static fn(): Response => Response::text($body)->withStatus(500),
        );
        self::assertArrayNotHasKey('Content-Encoding', $errorResponse->headers);
    }

    public function testSkipsForBinaryContentType(): void
    {
        $body = str_repeat('A', 2048);
        $request = new Request('GET', '/', '', ['accept-encoding' => 'gzip']);

        $response = $this->middleware->process(
            $request,
            static fn(): Response => new Response(200, $body, ['Content-Type' => 'image/png']),
        );

        self::assertArrayNotHasKey('Content-Encoding', $response->headers);
    }

    public function testSkipsForEmptyBody(): void
    {
        $request = new Request('GET', '/', '', ['accept-encoding' => 'gzip']);

        $response = $this->middleware->process(
            $request,
            static fn(): Response => Response::empty(204),
        );

        self::assertArrayNotHasKey('Content-Encoding', $response->headers);
    }

    public function testSkipsForChunkedResponse(): void
    {
        $body = str_repeat('A', 2048);
        $request = new Request('GET', '/', '', ['accept-encoding' => 'gzip']);

        $response = $this->middleware->process(
            $request,
            static fn(): Response => new Response(200, $body, [
                'Content-Type' => 'text/plain',
                'Transfer-Encoding' => 'chunked',
            ]),
        );

        self::assertArrayNotHasKey('Content-Encoding', $response->headers);
    }

    public function testCustomThresholdCompressesSmallerBodies(): void
    {
        $middleware = new CompressionMiddleware(threshold: 100);
        $body = str_repeat('A', 200);
        $request = new Request('GET', '/', '', ['accept-encoding' => 'gzip']);

        $response = $middleware->process($request, static fn(): Response => Response::text($body));

        self::assertSame('gzip', $response->headers['Content-Encoding'] ?? null);
    }

    public function testCustomLevelStillCompresses(): void
    {
        $middleware = new CompressionMiddleware(level: 9);
        $body = str_repeat('A', 2048);
        $request = new Request('GET', '/', '', ['accept-encoding' => 'gzip']);

        $response = $middleware->process($request, static fn(): Response => Response::text($body));

        self::assertSame('gzip', $response->headers['Content-Encoding'] ?? null);
        self::assertSame($body, gzdecode($response->body));
    }

    public function testCustomCompressibleTypesOnlyCompressesMatching(): void
    {
        $middleware = new CompressionMiddleware(compressibleTypes: ['text/html']);
        $body = str_repeat('A', 2048);
        $request = new Request('GET', '/', '', ['accept-encoding' => 'gzip']);

        $jsonResponse = $middleware->process(
            $request,
            static fn(): Response => Response::json(['data' => $body]),
        );
        self::assertArrayNotHasKey('Content-Encoding', $jsonResponse->headers);

        $htmlResponse = $middleware->process(
            $request,
            static fn(): Response => Response::html('<p>' . $body . '</p>'),
        );
        self::assertSame('gzip', $htmlResponse->headers['Content-Encoding'] ?? null);
    }

    public function testMergesVaryHeaderWithExisting(): void
    {
        $body = str_repeat('A', 2048);
        $request = new Request('GET', '/', '', ['accept-encoding' => 'gzip']);

        $response = $this->middleware->process(
            $request,
            static fn(): Response => Response::text($body)->withHeader('Vary', 'Origin'),
        );

        self::assertSame('Origin, Accept-Encoding', $response->headers['Vary'] ?? null);
    }

    public function testPreservesVaryHeaderIfAcceptEncodingAlreadyPresent(): void
    {
        $body = str_repeat('A', 2048);
        $request = new Request('GET', '/', '', ['accept-encoding' => 'gzip']);

        $response = $this->middleware->process(
            $request,
            static fn(): Response => Response::text($body)->withHeader('Vary', 'Accept-Encoding, Origin'),
        );

        self::assertSame('Accept-Encoding, Origin', $response->headers['Vary'] ?? null);
    }

    public function testVaryHeaderMergeIsCaseInsensitive(): void
    {
        $body = str_repeat('A', 2048);
        $request = new Request('GET', '/', '', ['accept-encoding' => 'gzip']);

        $response = $this->middleware->process(
            $request,
            static fn(): Response => Response::text($body)->withHeader('Vary', 'ACCEPT-ENCODING'),
        );

        self::assertSame('ACCEPT-ENCODING', $response->headers['Vary'] ?? null);
    }

    public function testConstructorRejectsNegativeThreshold(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CompressionMiddleware(threshold: -1);
    }

    public function testConstructorRejectsInvalidLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CompressionMiddleware(level: 10);
    }

    public function testLevelZeroSkipsCompression(): void
    {
        $middleware = new CompressionMiddleware(level: 0);
        $body = str_repeat('A', 2048);
        $request = new Request('GET', '/', '', ['accept-encoding' => 'gzip']);

        $response = $middleware->process($request, static fn(): Response => Response::text($body));

        self::assertArrayNotHasKey('Content-Encoding', $response->headers);
        self::assertSame($body, $response->body);
    }
}
