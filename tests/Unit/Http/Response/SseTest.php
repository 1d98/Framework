<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Response;

use Framework\Http\Response\Sse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sse::class)]
final class SseTest extends TestCase
{
    /**
     * Open a writeable in-memory stream for tests that need to read back
     * what Sse wrote. Stream is rewound before the assertion and closed
     * in tearDown-style local scope (fclose on shutdown).
     *
     * @return resource
     */
    private function openMemoryStream()
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream, 'Could not open memory stream');
        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function readAll(mixed $stream): string
    {
        self::assertIsResource($stream);
        rewind($stream);
        $contents = stream_get_contents($stream);
        self::assertIsString($contents);
        return $contents;
    }

    public function testEventWritesSingleLineData(): void
    {
        $stream = $this->openMemoryStream();

        Sse::event($stream, 'hello');

        $written = $this->readAll($stream);
        self::assertSame("data: hello\n\n", $written);
        fclose($stream);
    }

    public function testEventSplitsMultiLineDataIntoMultipleDataFields(): void
    {
        $stream = $this->openMemoryStream();

        Sse::event($stream, "line-one\nline-two\nline-three");

        $written = $this->readAll($stream);
        self::assertSame("data: line-one\ndata: line-two\ndata: line-three\n\n", $written);
        fclose($stream);
    }

    public function testEventEmitsEventFieldWhenProvided(): void
    {
        $stream = $this->openMemoryStream();

        Sse::event($stream, 'payload', event: 'user-login');

        $written = $this->readAll($stream);
        self::assertStringContainsString("data: payload\n", $written);
        self::assertStringContainsString("event: user-login\n", $written);
        self::assertStringEndsWith("\n", $written);
        fclose($stream);
    }

    public function testEventEmitsIdFieldWhenProvided(): void
    {
        $stream = $this->openMemoryStream();

        Sse::event($stream, 'payload', id: '42');

        $written = $this->readAll($stream);
        self::assertStringContainsString("id: 42\n", $written);
        fclose($stream);
    }

    public function testEventEmitsRetryFieldWhenProvided(): void
    {
        $stream = $this->openMemoryStream();

        Sse::event($stream, 'payload', retryMs: 5000);

        $written = $this->readAll($stream);
        self::assertStringContainsString("retry: 5000\n", $written);
        fclose($stream);
    }

    public function testEventEmitsAllFieldsTogether(): void
    {
        $stream = $this->openMemoryStream();

        Sse::event($stream, 'hello', event: 'greet', id: '7', retryMs: 3000);

        $written = $this->readAll($stream);
        self::assertSame("data: hello\nevent: greet\nid: 7\nretry: 3000\n\n", $written);
        fclose($stream);
    }

    public function testEventRejectsNulInData(): void
    {
        $stream = $this->openMemoryStream();
        $this->expectException(InvalidArgumentException::class);
        try {
            Sse::event($stream, "data\0poisoned");
        } finally {
            fclose($stream);
        }
    }

    public function testEventRejectsNulInEventName(): void
    {
        $stream = $this->openMemoryStream();
        $this->expectException(InvalidArgumentException::class);
        try {
            Sse::event($stream, 'ok', event: "evil\0name");
        } finally {
            fclose($stream);
        }
    }

    public function testEventRejectsCrlfInEventName(): void
    {
        // A CRLF in event name would let the attacker smuggle a different
        // SSE field (or arbitrary header) into the frame.
        $stream = $this->openMemoryStream();
        $this->expectException(InvalidArgumentException::class);
        try {
            Sse::event($stream, 'ok', event: "evil\r\nevent: pwned");
        } finally {
            fclose($stream);
        }
    }

    public function testEventRejectsCrlfInId(): void
    {
        $stream = $this->openMemoryStream();
        $this->expectException(InvalidArgumentException::class);
        try {
            Sse::event($stream, 'ok', id: "evil\r\nid: 99");
        } finally {
            fclose($stream);
        }
    }

    public function testEventRejectsNulInId(): void
    {
        $stream = $this->openMemoryStream();
        $this->expectException(InvalidArgumentException::class);
        try {
            Sse::event($stream, 'ok', id: "evil\0");
        } finally {
            fclose($stream);
        }
    }

    public function testEventRejectsNegativeRetryMs(): void
    {
        $stream = $this->openMemoryStream();
        $this->expectException(InvalidArgumentException::class);
        try {
            Sse::event($stream, 'ok', retryMs: -1);
        } finally {
            fclose($stream);
        }
    }

    public function testEventNormalizesCrlfToLfWithoutSplittingFrame(): void
    {
        // CRLF in payload should be normalized to LF — the SSE field
        // count must be the same as for the equivalent LF-only payload.
        $crlf = $this->openMemoryStream();
        $lf = $this->openMemoryStream();

        Sse::event($crlf, "line-one\r\nline-two");
        Sse::event($lf, "line-one\nline-two");

        self::assertSame($this->readAll($lf), $this->readAll($crlf));

        fclose($crlf);
        fclose($lf);
    }

    public function testEventNormalizesLoneCrToLf(): void
    {
        // Old-Mac line endings: bare \r becomes \n.
        $stream = $this->openMemoryStream();
        Sse::event($stream, "line-one\rline-two");

        $written = $this->readAll($stream);
        self::assertSame("data: line-one\ndata: line-two\n\n", $written);
        fclose($stream);
    }

    public function testCommentWritesColonPrefixOnEachLine(): void
    {
        $stream = $this->openMemoryStream();

        Sse::comment($stream, 'this is a comment');

        $written = $this->readAll($stream);
        self::assertSame(": this is a comment\n", $written);
        fclose($stream);
    }

    public function testCommentSplitsMultiLineText(): void
    {
        $stream = $this->openMemoryStream();

        Sse::comment($stream, "first\nsecond");

        $written = $this->readAll($stream);
        self::assertSame(": first\n: second\n", $written);
        fclose($stream);
    }

    public function testCommentRejectsNul(): void
    {
        $stream = $this->openMemoryStream();
        $this->expectException(InvalidArgumentException::class);
        try {
            Sse::comment($stream, "evil\0");
        } finally {
            fclose($stream);
        }
    }

    public function testPingWritesStandardPingFrame(): void
    {
        $stream = $this->openMemoryStream();

        Sse::ping($stream);

        $written = $this->readAll($stream);
        // SSE keep-alive ping: a comment line, terminated by a blank line.
        self::assertSame(": ping\n\n", $written);
        fclose($stream);
    }

    public function testRetryWritesRetryFieldWithBlankLineTerminator(): void
    {
        $stream = $this->openMemoryStream();

        Sse::retry($stream, 1500);

        $written = $this->readAll($stream);
        self::assertSame("retry: 1500\n\n", $written);
        fclose($stream);
    }

    public function testRetryRejectsNegativeMs(): void
    {
        $stream = $this->openMemoryStream();
        $this->expectException(InvalidArgumentException::class);
        try {
            Sse::retry($stream, -1);
        } finally {
            fclose($stream);
        }
    }

    public function testRetryAcceptsZero(): void
    {
        $stream = $this->openMemoryStream();

        Sse::retry($stream, 0);

        $written = $this->readAll($stream);
        self::assertSame("retry: 0\n\n", $written);
        fclose($stream);
    }

    public function testRejectsNonStreamResource(): void
    {
        // Use stream_context_create() as a non-stream resource type —
        // get_resource_type() returns 'stream-context' (not 'stream')
        // so the type guard MUST reject it.
        $context = stream_context_create();
        self::assertSame('stream-context', get_resource_type($context));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('open stream resource');
        Sse::event($context, 'data');
    }

    public function testRejectsClosedStreamResource(): void
    {
        $stream = $this->openMemoryStream();
        fclose($stream);
        // After fclose, is_resource() returns false — the guard catches
        // it before reaching get_resource_type.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('open stream resource');
        Sse::event($stream, 'data');
    }
}