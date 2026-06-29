<?php

declare(strict_types=1);

namespace Framework\Http\Response;

use Closure;
use Framework\Http\Cookie\Cookie;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Lazy response value object whose body is produced by a Closure emitter
 * at send() time. The body is never materialised in a PHP string, so SSE,
 * NDJSON, large-file download, and progress-style responses fit the same
 * ResponseInterface contract as buffered responses.
 *
 * Builder methods (withHeader/withHeaders/withStatus/withCookie/
 * withRequestId) return a NEW StreamedResponse with the updated field.
 *
 * send() emits status + headers, then invokes the emitter against a
 * freshly-opened `php://output` stream. When $contentLength is null and
 * the status is in [200, 299] \ {204}, send() wraps the stream in the
 * `http` chunked-transfer stream filter so the wire format is correct
 * without the caller thinking about it.
 *
 * If the emitter throws after headers have been flushed, send() writes a
 * sanitised failure note to STDERR (mirroring the redaction in
 * \Framework\Http\RequestLogger::sanitize — CR/LF/TAB collapsed to
 * spaces, C0 control bytes stripped, 256-byte cap) and rethrows. The
 * STDERR write is guarded against a closed stream so it never emits a
 * PHP warning that would itself land in the PHP error log.
 *
 * **Transfer-Encoding: chunked fallback.** When the `pecl_http`
 * extension is not compiled into PHP, the native `http` stream
 * filter is unavailable. `send()` falls back to a manual
 * chunked-encoding wrapper (RFC 7230 §4.1) registered as
 * `framework.chunked_writer`. The wire format is identical;
 * performance is slightly lower because every `fwrite()` is
 * intercepted by userland code. The shipped `ChunkedStreamWriter`
 * class is the implementation.
 *
 * Constraints enforced at construction:
 *  - $emitter must be a Closure (the property type enforces this);
 *  - $status in [100, 599];
 *  - $reasonPhrase: rejects [\r\n\0];
 *  - $contentLength: non-negative if set;
 *  - header name + value: rejects [\r\n:] and [\r\n\0] respectively;
 *  - cookies: typed Cookie VO, validates itself.
 *
 * @method static bool isHttpFilterAvailable()  Whether the native
 *     `pecl_http` chunked-transfer filter is available on this
 *     PHP build. `false` means `send()` will use the manual
 *     chunked-encoding fallback. Exposed for operators who want
 *     to detect the runtime capability (e.g. for a healthcheck).
 */
final readonly class StreamedResponse implements ResponseInterface
{
    /**
     * @param int                            $status
     * @param Closure(resource): void        $emitter       Writes the body to the supplied write stream.
     * @param array<string, string>          $headers
     * @param list<Cookie>                   $cookies
     * @param ?string                        $reasonPhrase
     * @param ?int                           $contentLength Null = unknown → use Transfer-Encoding: chunked.
     * @param ?string                        $contentType   Convenience: pre-sets Content-Type header.
     */
    public function __construct(
        public int $status,
        public Closure $emitter,
        public array $headers = [],
        public array $cookies = [],
        public ?string $reasonPhrase = null,
        public ?int $contentLength = null,
        public ?string $contentType = null,
    ) {
        if ($this->status < 100 || $this->status >= 600) {
            throw new InvalidArgumentException("StreamedResponse: status out of range: {$this->status}");
        }
        if ($this->reasonPhrase !== null && preg_match('/[\r\n\0]/', $this->reasonPhrase) === 1) {
            throw new InvalidArgumentException("StreamedResponse: reason phrase contains control character: {$this->reasonPhrase}");
        }
        if ($this->contentLength !== null && $this->contentLength < 0) {
            throw new InvalidArgumentException("StreamedResponse: contentLength cannot be negative: {$this->contentLength}");
        }
        foreach ($this->headers as $name => $value) {
            self::assertValidHeaderName((string) $name);
            self::assertValidHeaderValue((string) $value);
        }
        if ($this->contentType !== null) {
            self::assertValidHeaderValue($this->contentType);
        }
    }

    /**
     * Whether the native `pecl_http` chunked-transfer stream filter
     * is available on this PHP build. The filter is part of the
     * `pecl_http` extension, which is NOT bundled with PHP and is
     * rarely installed on stock builds. When this returns `false`,
     * `send()` falls back to {@see \Framework\Http\Response\ChunkedStreamWriter}
     * (RFC 7230 §4.1 manual chunked encoding).
     *
     * Cached with a function-local `static` because `final readonly
     * class` forbids class-level mutable state; `in_array()` over
     * the full filter registry is also non-trivial, so we only do
     * it once per process.
     */
    public static function isHttpFilterAvailable(): bool
    {
        static $cached = null;
        if ($cached !== true && $cached !== false) {
            $cached = in_array('http', stream_get_filters(), true);
        }
        return $cached;
    }

    public static function sse(Closure $emitter, int $status = 200): self
    {
        return new self(
            status: $status,
            emitter: $emitter,
            headers: [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    public static function ndjson(Closure $emitter, int $status = 200): self
    {
        return new self(
            status: $status,
            emitter: $emitter,
            headers: [
                'Content-Type' => 'application/x-ndjson; charset=utf-8',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    public function withHeader(string $name, string $value): self
    {
        self::assertValidHeaderName($name);
        self::assertValidHeaderValue($value);
        return new self(
            $this->status,
            $this->emitter,
            array_merge($this->headers, [$name => $value]),
            $this->cookies,
            $this->reasonPhrase,
            $this->contentLength,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            self::assertValidHeaderName((string) $name);
            self::assertValidHeaderValue((string) $value);
        }
        return new self(
            $this->status,
            $this->emitter,
            array_merge($this->headers, $headers),
            $this->cookies,
            $this->reasonPhrase,
            $this->contentLength,
        );
    }

    public function withStatus(int $status, ?string $reason = null): self
    {
        return new self(
            $status,
            $this->emitter,
            $this->headers,
            $this->cookies,
            $reason,
            $this->contentLength,
        );
    }

    public function withCookie(Cookie $c): self
    {
        return new self(
            $this->status,
            $this->emitter,
            $this->headers,
            [...$this->cookies, $c],
            $this->reasonPhrase,
            $this->contentLength,
        );
    }

    public function withRequestId(string $id): self
    {
        return $this->withHeader('X-Request-Id', $id);
    }

    public function send(): void
    {
        if (headers_sent($file, $line)) {
            throw new LogicException("StreamedResponse::send() called after headers were already sent (at {$file}:{$line})");
        }

        // RFC 9110 §6.4: 1xx, 204, 304 MUST NOT have a body.
        if ($this->status < 200 || $this->status === 204 || $this->status === 304) {
            throw new LogicException(sprintf(
                'StreamedResponse: status %d cannot have a streamed body (RFC 9110 §6.4 forbids a body for 1xx, 204, 304); use a Response instead',
                $this->status,
            ));
        }

        $statusLine = $this->buildStatusLine();
        if (preg_match('/[\r\n]/', $statusLine) === 1) {
            throw new InvalidArgumentException("Status line contains CRLF: {$statusLine}");
        }
        header($statusLine, true, $this->status);

        foreach ($this->toHeaderLines() as $line) {
            if (preg_match('/[\r\n]/', $line) === 1) {
                throw new InvalidArgumentException("Header line contains CRLF: {$line}");
            }
            header($line);
        }

        $useChunked = $this->contentLength === null;

        $stream = fopen('php://output', 'wb');
        if ($stream === false) {
            throw new LogicException('StreamedResponse::send() cannot open php://output');
        }

        $useFallbackChunked = false;
        $fallbackFilter = null;
        if ($useChunked) {
            if (self::isHttpFilterAvailable()) {
                $filter = stream_filter_append($stream, 'http', STREAM_FILTER_WRITE, ['transfer' => 'chunked']);
                if ($filter === false) {
                    fclose($stream);
                    throw new LogicException("StreamedResponse::send() cannot attach the 'http' chunked filter; check that the http stream filter is compiled in");
                }
            } else {
                // `pecl_http` is missing on this PHP build (the common case —
                // the extension is not bundled). Fall back to a userland
                // chunked-encoding wrapper that intercepts every fwrite()
                // against the emitter stream and frames each bucket in
                // RFC 7230 §4.1 chunk format. The final terminator chunk
                // (`0\r\n\r\n`) is emitted in the finally block below,
                // mirroring the native filter's close-time behaviour.
                //
                // stream_filter_register() returns false if the name is
                // already registered (legitimate on a second send() within
                // the same request), so we only treat the negative return
                // as an error when the filter is genuinely missing.
                if (!in_array('framework.chunked_writer', stream_get_filters(), true)) {
                    $registered = stream_filter_register('framework.chunked_writer', ChunkedStreamWriter::class);
                    if ($registered === false) {
                        fclose($stream);
                        throw new LogicException('StreamedResponse::send() cannot register the chunked-encoding fallback filter');
                    }
                }
                $writer = stream_filter_append($stream, 'framework.chunked_writer', STREAM_FILTER_WRITE);
                if ($writer === false) {
                    fclose($stream);
                    throw new LogicException('StreamedResponse::send() cannot attach the chunked-encoding fallback filter');
                }
                $fallbackFilter = $writer;
                $useFallbackChunked = true;
            }
        }

        try {
            ($this->emitter)($stream);
        } catch (Throwable $e) {
            if (headers_sent()) {
                // Collapse CR / LF / TAB to spaces (so a multi-line exception
                // trace cannot split a single failure across multiple STDERR
                // lines — mirrors the redaction in
                // \Framework\Http\RequestLogger::sanitize). Also strip NUL and
                // other C0 control bytes (0x00-0x08, 0x0B-0x0C, 0x0E-0x1F,
                // 0x7F) that could be interpreted as control sequences by a
                // terminal or downstream log-aggregator. Truncate to 256 bytes
                // to match RequestLogger's limit.
                $msg = $e->getMessage();
                $msg = strtr($msg, ["\r" => ' ', "\n" => ' ', "\t" => ' ']);
                $msg = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $msg) ?? '';
                if (function_exists('mb_substr')) {
                    $msg = mb_substr($msg, 0, 256);
                } else {
                    $msg = substr($msg, 0, 256);
                }
                // STDERR may be closed (CLI `2>/dev/null`, daemonized workers,
                // some web server configs); guard so we don't emit a PHP warning
                // that would itself land in PHP's error log.
                if (defined('STDERR') && is_resource(STDERR)) {
                    @fwrite(STDERR, sprintf(
                        "StreamedResponse::send() emitter threw after headers were sent: %s: %s\n",
                        $e::class,
                        $msg,
                    ));
                }
            }
            throw $e;
        } finally {
            if ($useFallbackChunked) {
                // Detach the manual encoder first so the terminator
                // is not itself re-framed (which would produce a
                // chunk of `5\r\n0\r\n\r\n\r\n` instead of the
                // canonical `0\r\n\r\n`). The @-suppressor on
                // stream_filter_remove handles the edge case where
                // PHP has already disposed of the filter resource
                // (it shouldn't, but the call can fail on Windows
                // when the stream is in a half-closed state).
                if ($fallbackFilter !== null) {
                    @stream_filter_remove($fallbackFilter);
                }
                // Emit the final terminator chunk for the manual
                // chunked encoder: `0\r\n\r\n`. The native pecl_http
                // filter emits this on close, so the fallback path
                // has to do it explicitly before fclose() drops the
                // stream. The @-suppressor handles the unusual case
                // of an already-closed stream (the emitter threw
                // before we got here).
                @fwrite($stream, "0\r\n\r\n");
            }
            fflush($stream);
            fclose($stream);
        }
    }

    private function buildStatusLine(): string
    {
        $reason = $this->reasonPhrase ?? StatusText::for($this->status) ?? '';
        return 'HTTP/1.1 ' . $this->status . ($reason !== '' ? ' ' . $reason : '');
    }

    /**
     * @return list<string>
     */
    private function toHeaderLines(): array
    {
        $headers = $this->headers;
        if ($this->contentType !== null && !array_key_exists('Content-Type', $headers)) {
            $headers['Content-Type'] = $this->contentType;
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        foreach ($this->cookies as $cookie) {
            $lines[] = 'Set-Cookie: ' . $cookie->toHeaderValue();
        }
        return $lines;
    }

    private static function assertValidHeaderName(string $name): void
    {
        if (preg_match('/[\r\n\0:]/', $name) === 1) {
            throw new InvalidArgumentException("Header name contains invalid character: {$name}");
        }
    }

    private static function assertValidHeaderValue(string $value): void
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException("Header value contains control character: {$value}");
        }
    }
}