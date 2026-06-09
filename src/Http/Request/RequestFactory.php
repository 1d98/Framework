<?php

declare(strict_types=1);

namespace Framework\Http\Request;

use Framework\Http\Exception\PayloadTooLargeHttpException;

final class RequestFactory
{
    /**
     * Build a {@see Request} from PHP's SAPI-superglobals.
     *
     * The factory owns ALL SAPI-bound concerns: header collection
     * (`getallheaders()` / `HTTP_*` fallback), body reading with the
     * byte cap, chunked-encoding enforcement, query-string split,
     * cookie parsing, and the host-related snapshotting that feeds
     * the {@see RequestHost} value object. The returned
     * {@see Request} is a plain DTO — all of this logic lives here so
     * the DTO can stay pure.
     *
     * The factory's `$maxBodyBytes` and the Request's `$maxBodyBytes`
     * constructor param MUST be the same value. This method enforces
     * that by computing the effective cap ONCE (`$maxBodyBytes ??
     * Request::MAX_BODY_BYTES`) and threading the single value into
     * BOTH `readBodyCapped()` (the SAPI read) AND the `new Request(...)`
     * call (the DTO's cap invariant). A caller cannot pass diverging
     * caps to the two layers — there is no second parameter.
     *
     * @param int|null $maxBodyBytes Hard cap on the request body in bytes.
     *        `null` (the default) means {@see Request::MAX_BODY_BYTES}.
     *        Requests declared with a larger `Content-Length` (or that
     *        stream past the cap) throw
     *        {@see PayloadTooLargeHttpException}. This same value is
     *        also written to the returned {@see Request}'s
     *        `$maxBodyBytes` so the constructor's cap invariant cannot
     *        fire later on the same body.
     * @param string|null $id Explicit request id. When `null`, the
     *        factory falls back to the `X-Request-Id` /
     *        `X-Correlation-Id` header (if present and well-formed)
     *        or a random 16-hex-char id.
     * @param array<string, mixed>|null $attributes Initial request
     *        attributes. When `null`, defaults to an empty array —
     *        middlewares that want to stash per-request state (e.g.
     *        the resolved route) use {@see Request::withAttribute()}.
     * @param list<string>|null $trustedProxies Trusted-proxy list
     *        captured into the returned {@see Request}'s
     *        {@see RequestHost} VO. When non-null, the no-arg
     *        overloads of {@see Request::isSecure()} and
     *        {@see Request::ip()} consult this list as a fallback.
     *        Per-call arguments at the call site always win.
     */
    public static function fromGlobals(
        ?int $maxBodyBytes = null,
        ?string $id = null,
        ?array $attributes = null,
        ?array $trustedProxies = null,
    ): Request {
        $method = strtoupper(self::serverString('REQUEST_METHOD', 'GET'));
        $uri = self::serverString('REQUEST_URI', '/');

        $parts = explode('?', $uri, 2);
        $path = $parts[0] !== '' ? $parts[0] : '/';
        $queryString = $parts[1] ?? '';

        $cap = $maxBodyBytes ?? Request::MAX_BODY_BYTES;
        $headers = self::collectHeaders();
        $body = self::readBodyCapped($cap);
        $cookies = self::parseCookieHeader(self::serverString('HTTP_COOKIE'));

        $host = new RequestHost(
            host: RequestHost::snapshotHostHeader(),
            isSecure: RequestHost::snapshotTransportHttps(),
            remoteAddr: RequestHost::snapshotRemoteAddr(),
            trustedProxies: $trustedProxies,
        );

        return new Request(
            method: $method,
            path: $path,
            queryString: $queryString,
            headers: $headers,
            body: $body,
            cookies: $cookies,
            maxBodyBytes: $cap,
            id: $id,
            attributes: $attributes,
            host: $host,
        );
    }

    private static function serverString(string $key, string $default = ''): string
    {
        $value = $_SERVER[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    /**
     * @return array<string, string>
     */
    private static function collectHeaders(): array
    {
        if (function_exists('getallheaders')) {
            /** @var array<string, string> $raw */
            $raw = getallheaders();
            $normalized = [];
            foreach ($raw as $name => $value) {
                $normalized[strtolower((string) $name)] = (string) $value;
            }
            return $normalized;
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_') && is_string($value)) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        $contentType = self::serverString('CONTENT_TYPE');
        if ($contentType !== '') {
            $headers['content-type'] = $contentType;
        }
        $contentLength = self::serverString('CONTENT_LENGTH');
        if ($contentLength !== '') {
            $headers['content-length'] = $contentLength;
        }

        /** @var array<string, string> $headers */
        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private static function parseCookieHeader(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $cookies = [];
        $pieces = explode(';', $raw);
        foreach ($pieces as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }
            $eqPos = strpos($piece, '=');
            if ($eqPos === false) {
                $cookies[$piece] = '';
                continue;
            }
            $name = substr($piece, 0, $eqPos);
            $value = substr($piece, $eqPos + 1);
            $cookies[$name] = $value;
        }

        /** @var array<string, string> $cookies */
        return $cookies;
    }

    /**
     * Read the raw request body, enforcing a hard byte cap to prevent OOM
     * DoS. Content-Length is honored when present (cheap fast path);
     * missing or unparseable Content-Length falls back to a streaming read
     * that aborts at cap+1 bytes, treating exact equality as truncation.
     *
     * @throws PayloadTooLargeHttpException When the body exceeds the cap
     *         or arrives with Transfer-Encoding: chunked on a non-multipart
     *         request.
     */
    private static function readBodyCapped(int $maxBodyBytes): string
    {
        self::assertContentLengthWithinCap($maxBodyBytes);
        self::assertChunkedEncodingAllowed();

        $input = @fopen('php://input', 'rb');
        if ($input === false) {
            return '';
        }

        try {
            return self::readStreamWithCap($input, $maxBodyBytes);
        } finally {
            fclose($input);
        }
    }

    /**
     * Throw when the request's declared Content-Length exceeds the cap.
     * A missing or non-numeric Content-Length is treated as unknown and
     * falls through to the streaming read.
     *
     * @throws PayloadTooLargeHttpException When the declared length is over the cap.
     */
    private static function assertContentLengthWithinCap(int $maxBodyBytes): void
    {
        $contentLength = self::serverString('CONTENT_LENGTH');
        if ($contentLength === '' || !ctype_digit($contentLength)) {
            return;
        }

        $declared = (int) $contentLength;
        if ($declared > $maxBodyBytes) {
            throw new PayloadTooLargeHttpException('Request body too large');
        }
    }

    /**
     * Reject `Transfer-Encoding: chunked` on non-multipart requests to avoid
     * a memory-DoS vector. RFC 7230 §4.1 lets servers refuse chunked; the
     * client must declare `Content-Length` instead. The exception is
     * `multipart/form-data`, which has its own per-part cap in
     * {@see \Framework\Http\Middleware\MultipartBodyParser} that enforces
     * the global limit incrementally.
     *
     * @throws PayloadTooLargeHttpException When chunked encoding is used on
     *         a non-multipart request.
     */
    private static function assertChunkedEncodingAllowed(): void
    {
        $transferEncoding = self::serverString('HTTP_TRANSFER_ENCODING');
        if ($transferEncoding === '' || stripos($transferEncoding, 'chunked') === false) {
            return;
        }

        $contentType = self::serverString('CONTENT_TYPE');
        $mime = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($mime === 'multipart/form-data') {
            return;
        }

        throw new PayloadTooLargeHttpException(
            'Chunked Transfer-Encoding is not supported for non-multipart requests; declare Content-Length instead',
        );
    }

    /**
     * Read a stream into a string, aborting after cap+1 bytes. Throws when
     * the stream contains more than the cap. Public so it can be tested
     * with a `php://memory` source; production callers should use
     * {@see self::fromGlobals()}.
     *
     * @param resource $stream Readable stream positioned at the start.
     * @throws PayloadTooLargeHttpException When the stream exceeds the cap.
     */
    public static function readStreamWithCap($stream, int $maxBodyBytes): string
    {
        $tmp = fopen('php://temp', 'w+b');
        if ($tmp === false) {
            return '';
        }

        try {
            $written = stream_copy_to_stream($stream, $tmp, $maxBodyBytes + 1);
            if ($written === $maxBodyBytes + 1) {
                throw new PayloadTooLargeHttpException('Request body too large');
            }
            rewind($tmp);
            $body = stream_get_contents($tmp);
            return $body === false ? '' : $body;
        } finally {
            fclose($tmp);
        }
    }
}
