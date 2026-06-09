<?php

declare(strict_types=1);

namespace Framework\Http\Multipart;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Exception\PayloadTooLargeHttpException;

/**
 * Pure-PHP parser for `multipart/form-data` request bodies.
 *
 * **In-memory parser.** This implementation takes the entire request
 * body as a string, walks the part boundaries, and returns a
 * {@see ParsedMultipart} value object whose `FilePart::$payload` is
 * the full file content. The middleware
 * ({@see \Framework\Http\Middleware\MultipartBodyParser}) is
 * responsible for everything that touches the outside world:
 *
 * - picking a temp directory
 * - calling `tempnam` / `file_put_contents`
 * - mapping parsed parts to `UploadedFile` instances
 * - cleaning up partial state on exception
 *
 * A future `StreamingMultipartParser` (or a `payload: resource` design
 * on {@see FilePart}) would change this: parts would be exposed as
 * open file handles or stream wrappers and the full payload would
 * never sit in PHP memory. Until that lands, callers must size the
 * cumulative body cap (`$maxBodyBytes`, defaulting to
 * {@see \Framework\Http\Request\Request::MAX_BODY_BYTES}) and the
 * per-part cap ({@see self::MAX_PART_BYTES}) so the body fits in RAM.
 *
 * The body is fully buffered in memory (constructor takes
 * `string $body`). The per-part and cumulative caps are checked
 * after each part is parsed; both checks operate on the
 * already-buffered body. The body cap is enforced upstream by
 * {@see \Framework\Http\Request\RequestFactory::readBodyCapped()}.
 * A future `StreamingMultipartParser` would consume the body
 * chunk-by-chunk without materializing it; this class is the
 * in-memory contract — see {@see FilePart::$payload}.
 *
 * **Limitations:** a 100MB upload is fully in PHP memory. The cap
 * protects against attacks (huge payloads) but does not protect
 * against a large-but-valid upload. Operators deploying this in a
 * memory-constrained environment should size `post_max_size` and
 * `memory_limit` accordingly.
 */
final class MultipartParser
{
    /**
     * Cap on a single part's raw payload (64 MiB). Enforced
     * **before** the part's bytes are sliced out of the body, so
     * an oversize part is rejected without ever allocating the
     * full string. The cumulative body cap (`$maxBodyBytes`)
     * remains the parameter-driven runtime cap.
     */
    public const int MAX_PART_BYTES = 64 * 1024 * 1024;

    public function __construct(
        private readonly string $body,
        private readonly string $boundary,
        private readonly int $maxBodyBytes,
        private readonly int $maxPartBytes = self::MAX_PART_BYTES,
    ) {
    }

    public function parse(): ParsedMultipart
    {
        $delimiter = '--' . $this->boundary;
        $closing = $delimiter . '--';
        $delimLen = strlen($delimiter);

        $bodyStart = strpos($this->body, $delimiter);
        if ($bodyStart === false) {
            throw new BadRequestHttpException('Malformed multipart body: no opening boundary');
        }

        $cursor = $bodyStart;
        $bodyLen = strlen($this->body);
        $form = [];
        $fileParts = [];
        /** @var int Defense-in-depth tracker; the SAPI cap is the primary limit. */
        $bytesWritten = 0;
        $fileIndex = 0;

        while (true) {
            if ($cursor + $delimLen + 2 <= $bodyLen
                && substr($this->body, $cursor, $delimLen + 2) === $closing) {
                break;
            }

            $cursor += $delimLen;
            if ($cursor < $bodyLen && $this->body[$cursor] === "\r") {
                $cursor++;
            }
            if ($cursor < $bodyLen && $this->body[$cursor] === "\n") {
                $cursor++;
            }

            $nextBoundary = strpos($this->body, $delimiter, $cursor);
            if ($nextBoundary === false) {
                throw new BadRequestHttpException('Malformed multipart body: no closing boundary');
            }

            $partEnd = $nextBoundary;
            if ($partEnd > $cursor && $this->body[$partEnd - 1] === "\n") {
                $partEnd--;
            }
            if ($partEnd > $cursor && $this->body[$partEnd - 1] === "\r") {
                $partEnd--;
            }

            $partLen = $partEnd - $cursor;
            if ($partLen < 0) {
                $partLen = 0;
            }

            if ($partLen > $this->maxPartBytes) {
                throw new PayloadTooLargeHttpException(
                    'Multipart part exceeds per-part cap of ' . $this->maxPartBytes
                    . ' bytes (got ' . $partLen . ')',
                );
            }

            $part = substr($this->body, $cursor, $partLen);

            $headerEnd = strpos($part, "\r\n\r\n");
            if ($headerEnd === false) {
                $cursor = $nextBoundary;
                continue;
            }

            $rawHeaders = substr($part, 0, $headerEnd);
            $payload = substr($part, $headerEnd + 4);

            $bytesWritten += strlen($payload);
            if ($bytesWritten > $this->maxBodyBytes) {
                throw new PayloadTooLargeHttpException('Multipart body exceeds size cap during parsing');
            }

            $parsedHeaders = self::parsePartHeaders($rawHeaders);
            $disposition = $parsedHeaders['content-disposition'] ?? '';
            $partType = $parsedHeaders['content-type'] ?? 'application/octet-stream';
            $dispositionParams = self::parseContentDisposition($disposition);
            $name = $dispositionParams['name'] ?? '';

            if ($name === '') {
                $cursor = $nextBoundary;
                continue;
            }

            if (array_key_exists('filename', $dispositionParams)) {
                $fileParts[] = new FilePart(
                    index: $fileIndex++,
                    fieldName: $name,
                    clientName: $dispositionParams['filename'],
                    type: $partType,
                    payload: $payload,
                );
                $cursor = $nextBoundary;
                continue;
            }

            if (array_key_exists($name, $form)) {
                $existing = $form[$name];
                $form[$name] = is_array($existing) ? [...$existing, $payload] : [$existing, $payload];
            } else {
                $form[$name] = $payload;
            }

            $cursor = $nextBoundary;
        }

        return new ParsedMultipart(
            form: $form,
            fileParts: $fileParts,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function parsePartHeaders(string $rawHeaders): array
    {
        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $headers[strtolower($name)] = trim($value);
        }
        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private static function parseContentDisposition(string $header): array
    {
        $params = [];
        if ($header === '') {
            return $params;
        }
        $segments = explode(';', $header);
        array_shift($segments);
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '' || !str_contains($segment, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $segment, 2);
            $key = strtolower(trim($key));
            $value = trim($value);
            if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
                $value = substr($value, 1, -1);
            }
            $params[$key] = $value;
        }
        return $params;
    }
}
