<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Response;

use Framework\Http\Response\ChunkedStreamWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChunkedStreamWriter::class)]
final class ChunkedStreamWriterTest extends TestCase
{
    /**
     * Open a writable in-memory stream with the chunked filter pre-attached.
     * Stream + filter are returned so the test can decide when to remove the
     * filter and when to close the stream. Closing order matters: the filter
     * must be removed before fclose() so the closing flush has somewhere to
     * land.
     *
     * @return array{0: resource, 1: resource}
     */
    private function openFilteredMemoryStream(): array
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream, 'Could not open php://memory');

        // stream_filter_register() returns false if the name is already
        // registered (a previous test in this process may have registered
        // it). The filter is per-process, not per-stream, so a successful
        // earlier registration is just as good as a fresh one. Only treat
        // the negative return as a failure when the filter is genuinely
        // missing from the registry afterwards.
        stream_filter_register('test.chunked', ChunkedStreamWriter::class);
        self::assertContains(
            'test.chunked',
            stream_get_filters(),
            'test.chunked filter is not registered',
        );

        $filter = stream_filter_append($stream, 'test.chunked', STREAM_FILTER_WRITE);
        self::assertIsResource($filter, 'Could not attach test.chunked filter');

        return [$stream, $filter];
    }

    /**
     * @param resource $stream
     * @param resource $filter
     */
    private function closeFilteredStream($stream, $filter): string
    {
        // Remove the filter BEFORE closing the underlying stream. This also
        // forces the closing flush so any partially-buffered data is written
        // to the underlying resource.
        @stream_filter_remove($filter);
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        self::assertIsString($contents);
        return $contents;
    }

    public function testChunkedStreamWriterWrapsEachBucketInSizeHeaderAndCrlf(): void
    {
        [$stream, $filter] = $this->openFilteredMemoryStream();

        // 11 bytes -> 0xb, 12 bytes -> 0xc, 5 bytes -> 0x5
        fwrite($stream, 'hello world');
        fwrite($stream, 'second chunk');
        fwrite($stream, 'third');

        $raw = $this->closeFilteredStream($stream, $filter);

        $expected = "b\r\nhello world\r\n"
                  . "c\r\nsecond chunk\r\n"
                  . "5\r\nthird\r\n";
        self::assertSame($expected, $raw);
    }

    public function testChunkedStreamWriterSkipsZeroLengthBuckets(): void
    {
        [$stream, $filter] = $this->openFilteredMemoryStream();

        // A zero-length fwrite is a no-op from a stream-buffer perspective,
        // but stream_bucket_make_writeable() can still surface a zero-length
        // bucket when buckets are batched by the filter chain. The filter
        // must skip those buckets rather than emit a size-only frame
        // (e.g. `0\r\n\r\n`), which some clients mis-interpret as an
        // end-of-stream signal.
        fwrite($stream, '');
        fwrite($stream, 'data');

        $raw = $this->closeFilteredStream($stream, $filter);

        self::assertSame("4\r\ndata\r\n", $raw);
    }

    public function testChunkedStreamWriterUsesLowercaseHex(): void
    {
        // RFC 7230 §4.1 says chunk-size is HEX (case-insensitive). The
        // implementation chooses lowercase — pin the choice so a future
        // change does not silently swap it and break the wire format
        // assumptions of clients that compare case-sensitively.
        [$stream, $filter] = $this->openFilteredMemoryStream();

        // 17 bytes -> 0x11, 31 bytes -> 0x1f, 255 bytes -> 0xff
        fwrite($stream, str_repeat('a', 17));
        fwrite($stream, str_repeat('b', 31));
        fwrite($stream, str_repeat('c', 255));

        $raw = $this->closeFilteredStream($stream, $filter);

        self::assertStringContainsString("11\r\n", $raw);
        self::assertStringContainsString("1f\r\n", $raw);
        self::assertStringContainsString("ff\r\n", $raw);
        self::assertStringNotContainsString("1F\r\n", $raw);
        self::assertStringNotContainsString("FF\r\n", $raw);
    }

    public function testChunkedStreamWriterHandlesUtf8BytesAsRawOctets(): void
    {
        // Chunk-size is a count of octets, not codepoints. A multi-byte UTF-8
        // sequence (e.g. `é` = 0xC3 0xA9, 2 bytes) must count as 2 in the
        // size header. This guards against a future refactor that switches
        // to mb_strlen() for "human-readable" sizes.
        [$stream, $filter] = $this->openFilteredMemoryStream();

        fwrite($stream, 'é'); // 2 octets

        $raw = $this->closeFilteredStream($stream, $filter);

        self::assertSame("2\r\né\r\n", $raw);
    }

    public function testChunkedStreamWriterDoesNotEmitTerminatorChunkOnItsOwn(): void
    {
        // The final `0\r\n\r\n` terminator is the responsibility of
        // StreamedResponse::send(), NOT the filter. Pinning this prevents a
        // future change to the filter from emitting the terminator inside
        // the filter (which would double-terminate when send() also emits
        // it).
        [$stream, $filter] = $this->openFilteredMemoryStream();

        fwrite($stream, 'payload');
        $raw = $this->closeFilteredStream($stream, $filter);

        self::assertSame("7\r\npayload\r\n", $raw);
        self::assertStringNotContainsString("0\r\n\r\n", $raw);
    }

    public function testChunkedStreamWriterPreservesBinaryContentUnchanged(): void
    {
        // The filter must NOT inspect or rewrite the payload bytes — only
        // frame them. Binary content (NULs, control bytes) must pass through
        // verbatim inside the chunk body. The filter does NOT reject NULs
        // in the payload because RFC 7230 §4.1 does not forbid them in chunk
        // bodies.
        [$stream, $filter] = $this->openFilteredMemoryStream();

        $payload = "\x00\x01\x02\xff\xfe\xfd";
        fwrite($stream, $payload);

        $raw = $this->closeFilteredStream($stream, $filter);

        self::assertSame("6\r\n" . $payload . "\r\n", $raw);
    }
}