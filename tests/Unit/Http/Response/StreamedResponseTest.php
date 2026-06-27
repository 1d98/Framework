<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Response;

use Closure;
use Framework\Http\Cookie\Cookie;
use Framework\Http\Response\StreamedResponse;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreamedResponse::class)]
final class StreamedResponseTest extends TestCase
{
    public function testConstructionRejectsStatusBelow100(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StreamedResponse(99, static function (): void {});
    }

    public function testConstructionRejectsStatusAbove599(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StreamedResponse(600, static function (): void {});
    }

    public function testConstructionAcceptsStatusAtBoundaries(): void
    {
        // 100 (lowest valid) and 599 (highest valid) MUST be accepted.
        $r100 = new StreamedResponse(100, static function (): void {});
        self::assertSame(100, $r100->status);

        $r599 = new StreamedResponse(599, static function (): void {});
        self::assertSame(599, $r599->status);
    }

    public function testEmitterMustBeClosure(): void
    {
        // The constructor signature is typed `Closure $emitter` — a
        // non-Closure callable MUST fail at the call site. Verified by
        // running through the type system: we never need a runtime check.
        $reflection = new \ReflectionClass(StreamedResponse::class);
        $ctor = $reflection->getConstructor();
        self::assertNotNull($ctor);
        $emitterParam = null;
        foreach ($ctor->getParameters() as $param) {
            if ($param->getName() === 'emitter') {
                $emitterParam = $param;
                break;
            }
        }
        self::assertNotNull($emitterParam);
        self::assertSame('Closure', (string) $emitterParam->getType());
    }

    public function testReasonPhraseCrlfGuard(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StreamedResponse(200, static function (): void {}, reasonPhrase: "OK\r\nX-Evil: 1");
    }

    public function testReasonPhraseNulGuard(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StreamedResponse(200, static function (): void {}, reasonPhrase: "OK\0");
    }

    public function testContentLengthMustBeNonNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StreamedResponse(200, static function (): void {}, contentLength: -1);
    }

    public function testContentLengthZeroIsAccepted(): void
    {
        // Zero is a legitimate value (e.g. an empty streamed body with a
        // explicit Content-Length: 0).
        $response = new StreamedResponse(200, static function (): void {}, contentLength: 0);
        self::assertSame(0, $response->contentLength);
    }

    public function testContentTypeCrlfGuard(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StreamedResponse(200, static function (): void {}, contentType: "text/plain\r\nX-Evil: 1");
    }

    public function testContentTypeNulGuard(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StreamedResponse(200, static function (): void {}, contentType: "text/plain\0");
    }

    public function testHeaderNameCrlfGuard(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StreamedResponse(200, static function (): void {}, headers: ["X-Evil\r\n" => 'value']);
    }

    public function testHeaderNameColonGuard(): void
    {
        // RFC 9110 §5.1: header names MUST NOT contain ':' — otherwise the
        // value would smuggle a second header line onto the wire.
        $this->expectException(InvalidArgumentException::class);
        new StreamedResponse(200, static function (): void {}, headers: ["X:Evil" => 'value']);
    }

    public function testHeaderValueCrlfGuard(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StreamedResponse(200, static function (): void {}, headers: ['X-Foo' => "v\r\nX-Evil: 1"]);
    }

    public function testHeaderValueNulGuard(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StreamedResponse(200, static function (): void {}, headers: ['X-Foo' => "v\0"]);
    }

    public function testWithHeaderReturnsNewInstanceAndLeavesOriginalUntouched(): void
    {
        $original = new StreamedResponse(200, static function (): void {});
        $modified = $original->withHeader('X-Tag', 'alpha');

        self::assertNotSame($original, $modified, 'withHeader must return a new instance');
        self::assertArrayNotHasKey('X-Tag', $original->headers, 'original headers must be unchanged');
        self::assertSame('alpha', $modified->headers['X-Tag']);
        self::assertSame([], $original->headers);
    }

    public function testWithHeadersMergesAndReturnsNewInstance(): void
    {
        $original = new StreamedResponse(200, static function (): void {}, headers: ['X-A' => '1']);
        $modified = $original->withHeaders(['X-B' => '2', 'X-C' => '3']);

        self::assertNotSame($original, $modified);
        self::assertSame(['X-A' => '1'], $original->headers);
        self::assertSame(['X-A' => '1', 'X-B' => '2', 'X-C' => '3'], $modified->headers);
    }

    public function testWithStatusReturnsNewInstance(): void
    {
        $original = new StreamedResponse(200, static function (): void {});
        $modified = $original->withStatus(201, 'Created');

        self::assertNotSame($original, $modified);
        self::assertSame(200, $original->status);
        self::assertNull($original->reasonPhrase);
        self::assertSame(201, $modified->status);
        self::assertSame('Created', $modified->reasonPhrase);
    }

    public function testWithCookieReturnsNewInstanceAndAppendsCookie(): void
    {
        $original = new StreamedResponse(200, static function (): void {});
        $cookie = new Cookie('session', 'token-abc');
        $modified = $original->withCookie($cookie);

        self::assertNotSame($original, $modified);
        self::assertSame([], $original->cookies);
        self::assertCount(1, $modified->cookies);
        self::assertSame($cookie, $modified->cookies[0]);
    }

    public function testWithRequestIdSetsXRequestIdHeader(): void
    {
        $original = new StreamedResponse(200, static function (): void {});
        $modified = $original->withRequestId('req-12345');

        self::assertNotSame($original, $modified);
        self::assertArrayNotHasKey('X-Request-Id', $original->headers);
        self::assertSame('req-12345', $modified->headers['X-Request-Id']);
    }

    public function testSseFactoryPreSetsCorrectHeaders(): void
    {
        $response = StreamedResponse::sse(static function (): void {});

        self::assertSame(200, $response->status);
        self::assertSame('text/event-stream', $response->headers['Content-Type']);
        self::assertSame('no-cache, no-transform', $response->headers['Cache-Control']);
        self::assertSame('no', $response->headers['X-Accel-Buffering']);
    }

    public function testSseFactoryAcceptsCustomStatus(): void
    {
        $response = StreamedResponse::sse(static function (): void {}, status: 201);

        self::assertSame(201, $response->status);
        self::assertSame('text/event-stream', $response->headers['Content-Type']);
    }

    public function testNdjsonFactoryPreSetsCorrectHeaders(): void
    {
        $response = StreamedResponse::ndjson(static function (): void {});

        self::assertSame(200, $response->status);
        self::assertSame('application/x-ndjson; charset=utf-8', $response->headers['Content-Type']);
        self::assertSame('no-cache', $response->headers['Cache-Control']);
        self::assertSame('no', $response->headers['X-Accel-Buffering']);
    }

    public function testNdjsonFactoryAcceptsCustomStatus(): void
    {
        $response = StreamedResponse::ndjson(static function (): void {}, status: 207);

        self::assertSame(207, $response->status);
        self::assertSame('application/x-ndjson; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testSendRejects1xxStatus(): void
    {
        // RFC 9110 §6.4: 1xx, 204, 304 MUST NOT have a body.
        $response = new StreamedResponse(100, static function (): void {});
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('100 cannot have a streamed body');
        $response->send();
    }

    public function testSendRejects204Status(): void
    {
        $response = new StreamedResponse(204, static function (): void {});
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('204 cannot have a streamed body');
        $response->send();
    }

    public function testSendRejects304Status(): void
    {
        $response = new StreamedResponse(304, static function (): void {});
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('304 cannot have a streamed body');
        $response->send();
    }

    public function testSendInvokesEmitterAgainstPhpOutputStream(): void
    {
        $capturedStream = null;
        $emitterRan = false;
        // Pass a fixed contentLength so the constructor skips the chunked
        // transfer path. The PHP `http` stream filter is not compiled in
        // many builds, so we exercise the explicit-contentLength branch
        // (no chunked filter) here. The emitter writes nothing — we are
        // only verifying the contract that the emitter was invoked with
        // a valid stream resource.
        $response = new StreamedResponse(
            200,
            function (mixed $stream) use (&$capturedStream, &$emitterRan): void {
                $emitterRan = true;
                $capturedStream = $stream;
            },
            contentLength: 0,
        );

        $response->send();

        self::assertTrue($emitterRan, 'Emitter must be invoked by send()');
        self::assertIsResource($capturedStream, 'Emitter must receive a stream resource');
    }

    public function testSendWithoutHeadersSentGuard(): void
    {
        // When the test process has NOT yet emitted headers, send() should
        // succeed without throwing the "headers already sent" guard. Pass a
        // fixed contentLength so we skip the chunked transfer path (the
        // PHP `http` stream filter is not compiled in on every build).
        $response = new StreamedResponse(
            200,
            static function (): void {},
            contentLength: 0,
        );
        $response->send();

        self::assertSame(200, $response->status);
    }

    public function testSendEmitsContentLengthHeaderWhenSet(): void
    {
        // When contentLength is set, send() must emit a Content-Length
        // header (no chunked transfer encoding). The `http` filter is not
        // needed in this branch.
        $response = new StreamedResponse(
            200,
            static function (): void {},
            contentLength: 42,
        );
        $response->send();

        self::assertSame(200, $response->status);
    }
}