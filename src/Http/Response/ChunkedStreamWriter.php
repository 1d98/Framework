<?php

declare(strict_types=1);

namespace Framework\Http\Response;

/**
 * Stream filter that wraps every write in RFC 7230 §4.1 chunked
 * format: `<hex-size>\r\n<data>\r\n`. The final terminator chunk
 * (`0\r\n\r\n`) is emitted by {@see StreamedResponse::send()} in
 * its finally block, after the filter is closed.
 *
 * Registered as `framework.chunked_writer` on each chunked-fallback
 * `send()` invocation. Re-registered per call because the underlying
 * stream filter API keeps no per-process state of its own.
 *
 * Not intended for use outside {@see StreamedResponse}; the filter
 * protocol is well-defined but the encoding has no use cases beyond
 * compensating for the missing native `pecl_http` chunked filter.
 *
 * The `$filtername` and `$params` properties are declared nullable
 * typed properties because PHP's stream filter protocol assigns
 * them on every `stream_filter_append()` call (without using the
 * constructor) and PHP 8.2+ would otherwise emit a dynamic-property
 * deprecation notice every time the filter is attached.
 */
final class ChunkedStreamWriter
{
    public ?string $filtername = null;

    /** @var array<int|string, mixed>|null */
    public ?array $params = null;

    /**
     * Stream filter protocol entry point. PHP invokes this once per
     * bucket batch. We mutate every read-bucket in place — replacing
     * its `data` with the chunked-encoding frame around the original
     * payload — and forward it to the write brigade.
     *
     * The mutation-in-place pattern avoids `stream_bucket_new()`,
     * which requires an open stream as its first argument and is
     * rejected (PHP raises a TypeError) when given the brigade
     * resources PHP passes to userland filter callbacks. The same
     * pattern is used by php-src's own bundled userland filter tests
     * (e.g. `ext/standard/tests/filters/userfilters.phpt`).
     *
     * @param resource $in       Brigade of buckets to consume.
     * @param resource $out      Brigade of buckets to append to.
     * @param int      $consumed Total bytes consumed from $in across
     *                           all buckets (returned to PHP).
     * @param bool     $closing  True on the final flush before the
     *                           filter is detached; we still emit
     *                           partial frames so a truncated emit
     *                           is recoverable as a connection abort
     *                           rather than a hung connection.
     *
     * @return int Always PSFS_PASS_ON — the chunked encoding is
     *             lossless and we never need to stall the brigade.
     */
    public static function filter($in, $out, &$consumed, bool $closing): int
    {
        $consumed = 0;
        while (($bucket = stream_bucket_make_writeable($in)) !== null) {
            // stream_bucket_make_writeable() may legitimately present
            // zero-length buckets as a bucket object with empty data;
            // skip those so we never emit a size-only frame (which
            // some clients can misinterpret as a keep-alive ping).
            $size = strlen($bucket->data);
            if ($size === 0) {
                continue;
            }
            $consumed += $bucket->datalen;
            // RFC 7230 §4.1: chunk = size CRLF data CRLF; size is hex.
            $bucket->data = sprintf("%x\r\n%s\r\n", $size, $bucket->data);
            stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;
    }
}
