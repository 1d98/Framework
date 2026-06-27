<?php

declare(strict_types=1);

namespace Framework\Http\Response;

use InvalidArgumentException;

/**
 * Static helpers for writing Server-Sent Events wire format from inside
 * a StreamedResponse emitter built with {@see StreamedResponse::sse()}.
 *
 * Sanitization: every input is checked for CR / LF / NUL before being
 * written to the stream. Newlines in $data are preserved (RFC: each
 * line gets its own `data:` prefix); newlines in $event / $id are
 * rejected outright (they would let a poisoned value smuggle a
 * different SSE field into the frame).
 *
 * Stream parameter type: PHP 8.5 still has no native stream-resource
 * type, so $stream is documented as `resource` via PHPDoc. Callers that
 * pass a non-stream resource (or non-resource at all) get a clear
 * InvalidArgumentException at the call site.
 */
final readonly class Sse
{
    /**
     * @param resource $stream  Open write stream, typically the `php://output`
     *                          handle handed to the emitter by StreamedResponse.
     * @param string   $data    UTF-8 payload; newlines become per-line `data:` fields.
     * @param ?string  $event   Event name (single line; no CR/LF/NUL allowed).
     * @param ?string  $id      Last-Event-ID value (single line; no CR/LF/NUL allowed).
     * @param ?int     $retryMs Client reconnection delay in milliseconds (>= 0).
     */
    public static function event(
        $stream,
        string $data,
        ?string $event = null,
        ?string $id = null,
        ?int $retryMs = null,
    ): void {
        self::assertStream($stream);
        $data = self::sanitizePayload($data);
        foreach (explode("\n", $data) as $line) {
            fwrite($stream, 'data: ' . $line . "\n");
        }
        if ($event !== null) {
            self::sanitizeField('event', $event);
            fwrite($stream, 'event: ' . $event . "\n");
        }
        if ($id !== null) {
            self::sanitizeField('id', $id);
            fwrite($stream, 'id: ' . $id . "\n");
        }
        if ($retryMs !== null) {
            if ($retryMs < 0) {
                throw new InvalidArgumentException("Sse::event: retryMs cannot be negative: {$retryMs}");
            }
            fwrite($stream, 'retry: ' . $retryMs . "\n");
        }
        fwrite($stream, "\n");
    }

    /**
     * @param resource $stream Open write stream.
     * @param string   $text   Comment text; lines are each prefixed with `: `.
     */
    public static function comment($stream, string $text): void
    {
        self::assertStream($stream);
        $text = self::sanitizePayload($text);
        fwrite($stream, ': ' . str_replace("\n", "\n: ", $text) . "\n");
    }

    /**
     * @param resource $stream Open write stream.
     */
    public static function ping($stream): void
    {
        self::assertStream($stream);
        fwrite($stream, ": ping\n\n");
    }

    /**
     * @param resource $stream  Open write stream.
     * @param int      $retryMs Client reconnection delay in milliseconds (>= 0).
     */
    public static function retry($stream, int $retryMs): void
    {
        self::assertStream($stream);
        if ($retryMs < 0) {
            throw new InvalidArgumentException("Sse::retry: retryMs cannot be negative: {$retryMs}");
        }
        fwrite($stream, "retry: {$retryMs}\n\n");
    }

    private static function sanitizePayload(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw new InvalidArgumentException('Sse: payload contains NUL byte');
        }
        // CRLF → LF, then lone CR → LF (handles old-Mac line endings).
        $value = str_replace("\r\n", "\n", $value);
        $value = str_replace("\r", "\n", $value);
        return $value;
    }

    private static function sanitizeField(string $name, string $value): void
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException("Sse: {$name} contains control character: {$value}");
        }
    }

    /**
     * @param mixed $stream
     */
    private static function assertStream($stream): void
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('Sse: expected an open stream resource, got ' . get_debug_type($stream));
        }
    }
}