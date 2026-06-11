<?php

declare(strict_types=1);

namespace Framework\Http\Middleware;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Multipart\FilenameSanitizer;
use Framework\Http\Multipart\MultipartEnvelope;
use Framework\Http\Multipart\MultipartParser;
use Framework\Http\Multipart\ParsedMultipart;
use Framework\Http\Multipart\SuperglobalFormReader;
use Framework\Http\Multipart\TempFilePool;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\UploadedFile;

/**
 * SAPI-bound adapter that turns a `multipart/form-data` request body
 * into a form map and an uploaded-files map. The actual RFC 2388
 * boundary / `Content-Disposition` / per-part-header parsing lives
 * in {@see \Framework\Http\Multipart\MultipartParser}; envelope-level
 * checks in {@see MultipartEnvelope}; superglobal fallback in
 * {@see SuperglobalFormReader}; temp-file lifecycle in
 * {@see TempFilePool}; filename sanitization in
 * {@see FilenameSanitizer}. This middleware is the wiring layer.
 *
 * The body is fully buffered in memory (the body is already a
 * `string` on the `Request` by the time this middleware sees it).
 * The per-part and cumulative caps are checked after each part is
 * parsed inside {@see \Framework\Http\Multipart\MultipartParser};
 * both checks operate on the already-buffered body. The body cap
 * itself is enforced upstream by
 * {@see \Framework\Http\Request\RequestFactory::readBodyCapped()}.
 * A future `StreamingMultipartParser` would consume the body
 * chunk-by-chunk from `php://input` without materializing it; this
 * middleware is the in-memory wiring layer — see
 * {@see \Framework\Http\Multipart\FilePart::$payload}.
 *
 * **Limitations:** a 100MB upload is fully resident in PHP memory
 * before this middleware runs. The cap protects against attacks
 * (huge payloads) but does not protect against a large-but-valid
 * upload. Operators deploying this in a memory-constrained
 * environment should size `post_max_size` and `memory_limit`
 * accordingly.
 */
final class MultipartBodyParser implements MiddlewareInterface
{
    private readonly string $tmpDir;

    private readonly int $maxBodyBytes;

    private readonly int $maxPartBytes;

    /**
     * @param int $maxBodyBytes Cumulative body cap in bytes. Defaults to
     *     {@see Request::MAX_BODY_BYTES}. The cap is enforced upstream
     *     by {@see \Framework\Http\Request\RequestFactory::readBodyCapped()},
     *     so by the time this middleware runs the body is always
     *     within it. Pass a smaller value to add defense-in-depth at
     *     the middleware layer.
     * @param int $maxPartBytes Per-part cap in bytes. Defaults to
     *     {@see \Framework\Http\Multipart\MultipartParser::MAX_PART_BYTES}.
     *     Tighten this for endpoints that accept many small uploads
     *     to bound worst-case memory per part.
     */
    public function __construct(
        ?string $tmpDir = null,
        int $maxBodyBytes = 0,
        int $maxPartBytes = 0,
    ) {
        $this->tmpDir = $tmpDir ?? __DIR__ . '/../../../var/tmp/';
        $this->maxBodyBytes = $maxBodyBytes > 0 ? $maxBodyBytes : Request::MAX_BODY_BYTES;
        $this->maxPartBytes = $maxPartBytes > 0 ? $maxPartBytes : MultipartParser::MAX_PART_BYTES;
    }

    public function process(Request $request, callable $next): Response
    {
        if (!in_array($request->method, ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        if ($request->files !== null) {
            return $next($request);
        }

        $contentType = $request->header('content-type') ?? '';
        $mime = strtolower(trim(explode(';', $contentType, 2)[0]));

        if ($mime !== 'multipart/form-data') {
            return $next($request);
        }

        $boundary = MultipartEnvelope::extractBoundary($contentType);
        if ($boundary === '') {
            throw new BadRequestHttpException('Invalid multipart Content-Type: missing boundary');
        }

        if ($request->body === '' && SuperglobalFormReader::hasFormData()) {
            return $next($request->withForm(SuperglobalFormReader::readPostFields())->withFiles(SuperglobalFormReader::readUploadedFiles()));
        }

        // The body cap is enforced upstream (Request::__construct /
        // RequestFactory::fromGlobals). This middleware assumes the
        // body is already within cap; an oversize body would have
        // been rejected before reaching here. The per-PART cap is
        // enforced inside MultipartParser (see MAX_PART_BYTES).
        MultipartEnvelope::assertContentLengthMatches($request);

        if ($request->body === '') {
            return $next($request->withForm([])->withFiles([]));
        }

        $parsed = (new MultipartParser(
            $request->body,
            $boundary,
            $this->maxBodyBytes,
            $this->maxPartBytes,
        ))->parse();
        $pool = new TempFilePool($this->tmpDir);

        try {
            $files = self::flushFileParts($pool, $parsed);
        } catch (\Throwable $e) {
            $pool->release();
            throw $e;
        }

        return $next($request->withForm($parsed->form)->withFiles($files));
    }

    /**
     * @return array<string, UploadedFile|list<UploadedFile>>
     */
    private static function flushFileParts(TempFilePool $pool, ParsedMultipart $parsed): array
    {
        $files = [];

        foreach ($parsed->fileParts as $part) {
            $entry = $pool->write($part->payload);
            if ($entry['error'] !== null) {
                continue;
            }

            $file = new UploadedFile(
                name: FilenameSanitizer::sanitize($part->clientName),
                type: $part->type,
                tmpPath: $entry['path'],
                error: UPLOAD_ERR_OK,
                size: $entry['size'],
            );

            $name = $part->fieldName;
            if (array_key_exists($name, $files)) {
                $existing = $files[$name];
                $files[$name] = is_array($existing) ? [...$existing, $file] : [$existing, $file];
            } else {
                $files[$name] = $file;
            }
        }

        return $files;
    }
}
