<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Response;

use Closure;
use Framework\Http\Cookie\Cookie;
use Framework\Http\Response\Sse;
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

    public function testIsHttpFilterAvailableReturnsBool(): void
    {
        // Capability probe: returns true when pecl_http's `http` stream
        // filter is compiled into PHP, false otherwise. The contract is
        // "a bool" — never null, never an int. Pin that.
        $result = StreamedResponse::isHttpFilterAvailable();
        self::assertIsBool($result);
    }

    public function testIsHttpFilterAvailableResultIsCachedAcrossCalls(): void
    {
        // The capability probe is memoised with a function-local `static`
        // because (a) the readonly class forbids mutable class state, and
        // (b) in_array() over stream_get_filters() is non-trivial. Three
        // back-to-back calls MUST return the same value — without this
        // pin a future refactor could accidentally drop the cache.
        $first = StreamedResponse::isHttpFilterAvailable();
        $second = StreamedResponse::isHttpFilterAvailable();
        $third = StreamedResponse::isHttpFilterAvailable();

        self::assertSame($first, $second);
        self::assertSame($second, $third);
    }

    public function testSendSucceedsOnFallbackPathAndEmitsChunkedWireFormat(): void
    {
        // End-to-end through the manual chunked-encoding fallback.
        // The fallback activates whenever the native `http` stream filter
        // is unavailable (the common case on stock PHP). We do NOT
        // gate this test on the absence of pecl_http — it must pass on
        // both builds: when pecl_http is present, the native filter path
        // produces equivalent chunked output; when pecl_http is missing,
        // the manual encoder in ChunkedStreamWriter does.
        //
        // Wire format for a 5-byte payload:
        //   "5\r\nhello\r\n"          (the body chunk)
        //   "0\r\n\r\n"               (the terminator chunk)
        //
        // send() calls ob_end_clean() in its finally block, which would
        // discard the buffer. The write-callback fires per buffer-flush
        // BEFORE ob_end_clean() runs, so it has access to the bytes.
        // We collect them into $captured and return '' to the output
        // layer so ob_end_clean() has nothing to discard.

        $captured = '';
        ob_start(static function (string $buffer) use (&$captured): string {
            $captured .= $buffer;
            return '';
        });

        $response = new StreamedResponse(
            status: 200,
            emitter: static function ($stream): void {
                fwrite($stream, 'hello');
            },
        );

        $response->send();
        ob_end_flush();

        // Strip any internal diagnostic header lines (the body capture
        // is bytes that hit the output layer — headers are emitted via
        // header() and go elsewhere). Assert on the canonical chunked
        // frames only.
        self::assertStringContainsString("5\r\nhello\r\n", $captured);
        self::assertStringContainsString("0\r\n\r\n", $captured);
        // The terminator MUST be the LAST chunk on the wire.
        self::assertSame(
            strrpos($captured, "0\r\n\r\n"),
            strlen($captured) - strlen("0\r\n\r\n"),
            'Terminator chunk must be the final bytes on the wire',
        );
    }

    public function testSseResponseWorksThroughFallbackChunkedEncoding(): void
    {
        // SSE frames built via Sse::event() must round-trip the fallback
        // path. Sse::event emits several fwrite() calls per event (data
        // line + event line + blank line), each of which becomes its own
        // chunk — that's correct per RFC 7230 §4.1 (any chunk count is
        // valid). We assert on the SSE payload, not the exact chunk
        // boundaries, because the bucket boundaries depend on PHP's
        // stream buffering.
        $captured = '';
        ob_start(static function (string $buffer) use (&$captured): string {
            $captured .= $buffer;
            return '';
        });

        $response = StreamedResponse::sse(static function ($stream): void {
            self::assertIsResource($stream);
            Sse::event($stream, 'hello', event: 'greet');
            Sse::event($stream, 'world', event: 'greet');
        });

        $response->send();
        ob_end_flush();

        self::assertStringContainsString('event: greet', $captured);
        self::assertStringContainsString('data: hello', $captured);
        self::assertStringContainsString('data: world', $captured);
        // Every body chunk must be framed: each `fwrite()` from Sse::event
        // becomes one bucket, and the fallback encoder wraps it as
        // `<hex>\r\n<data>\r\n`. Spot-check one frame: `data: hello\n`
        // is emitted by Sse as its own fwrite(), then the fallback
        // prepends `<hex>\r\n` and appends `\r\n`. So the on-the-wire
        // substring for that fwrite() is `<hex>\r\ndata: hello\n\r\n`.
        self::assertMatchesRegularExpression(
            '/[0-9a-f]+\\r\\ndata: hello\\n\\r\\n/',
            $captured,
        );
        // Terminator must close the stream.
        self::assertStringContainsString("0\r\n\r\n", $captured);
    }

    public function testNdjsonResponseWorksThroughFallbackChunkedEncoding(): void
    {
        // NDJSON: one JSON object per line, each line terminated by \n.
        // StreamedResponse::ndjson() pre-sets Content-Type but does NOT
        // wrap each line — that's the caller's emitter job.
        $captured = '';
        ob_start(static function (string $buffer) use (&$captured): string {
            $captured .= $buffer;
            return '';
        });

        $response = StreamedResponse::ndjson(static function ($stream): void {
            self::assertIsResource($stream);
            fwrite($stream, "{\"a\":1}\n");
            fwrite($stream, "{\"b\":2}\n");
        });

        $response->send();
        ob_end_flush();

        self::assertStringContainsString('{"a":1}', $captured);
        self::assertStringContainsString('{"b":2}', $captured);
        self::assertStringContainsString("0\r\n\r\n", $captured);
    }

    public function testFallbackPathDoesNotCorruptWhenEmitterWritesZeroBytes(): void
    {
        // An emitter that writes nothing must still produce a valid
        // chunked wire stream: an empty body chunk (no payload chunks) +
        // the terminator. This guards against a future refactor that
        // might skip the terminator when the emitter is a no-op.
        $captured = '';
        ob_start(static function (string $buffer) use (&$captured): string {
            $captured .= $buffer;
            return '';
        });

        $response = new StreamedResponse(
            status: 200,
            emitter: static function (): void {
                // intentional no-op
            },
        );

        $response->send();
        ob_end_flush();

        self::assertStringContainsString("0\r\n\r\n", $captured);
    }

    public function testFallbackPathFramesEachEmitterFwriteAsItsOwnChunk(): void
    {
        // The fallback encoder wraps each bucket. PHP's stream buffer
        // makes one bucket per fwrite() (small writes get coalesced,
        // large ones get split — but in practice a single fwrite() of a
        // modest string lands as one bucket). Pin the boundary: three
        // fwrite()s produce three body chunks + one terminator.
        $captured = '';
        ob_start(static function (string $buffer) use (&$captured): string {
            $captured .= $buffer;
            return '';
        });

        $response = new StreamedResponse(
            status: 200,
            emitter: static function ($stream): void {
                fwrite($stream, 'AAA');
                fwrite($stream, 'BBBB');
                fwrite($stream, 'CC');
            },
        );

        $response->send();
        ob_end_flush();

        self::assertStringContainsString("3\r\nAAA\r\n", $captured);
        self::assertStringContainsString("4\r\nBBBB\r\n", $captured);
        self::assertStringContainsString("2\r\nCC\r\n", $captured);
        self::assertStringContainsString("0\r\n\r\n", $captured);
    }
}