<?php

declare(strict_types=1);

namespace Framework\Http\Multipart;

/**
 * Result of {@see MultipartParser::parse()}.
 *
 * **In-memory parser.** The parser is pure PHP and never touches
 * the filesystem, but it also never streams: the entire body
 * lands in `$fileParts[*]->payload` as a string. The middleware
 * performs the I/O (temp-file creation, `file_put_contents`,
 * cleanup on throw) via {@see TempFilePool}.
 *
 * A future `StreamingMultipartParser` (or a `payload: resource`
 * redesign on {@see FilePart}) would change the in-memory contract
 * — parts would be exposed as open file handles and the full
 * payload would never sit in PHP memory. Until that lands, callers
 * must size the per-part cap ({@see MultipartParser::MAX_PART_BYTES})
 * and the cumulative body cap
 * ({@see \Framework\Http\Request\Request::MAX_BODY_BYTES}) so the
 * body fits in RAM.
 *
 * - `$form` mirrors the same shape `Request::withForm()` expects.
 * - `$fileParts` is the in-memory file-shaped parts the middleware
 *   must still flush to disk before they can be exposed as
 *   `UploadedFile`s. Each carries the raw payload bytes plus the
 *   already-extracted `Content-Disposition` and `Content-Type`
 *   fields, so the middleware is just plumbing.
 */
final readonly class ParsedMultipart
{
    /**
     * @param array<string, string|list<string>> $form
     * @param list<FilePart> $fileParts
     */
    public function __construct(
        public array $form = [],
        public array $fileParts = [],
    ) {
    }
}
