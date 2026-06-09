<?php

declare(strict_types=1);

namespace Framework\Http\Multipart;

/**
 * One file-shaped multipart part, as produced by {@see MultipartParser::parse()}.
 *
 * **In-memory payload.** The `$payload` field is the **entire** file
 * content as a string. The parser is fully in-memory: the bytes
 * live here until the middleware flushes them to a temp path. A
 * future `StreamingMultipartParser` (or a `payload: resource`
 * redesign) would change this — the part would carry a stream /
 * open file handle instead, and the full bytes would never sit in
 * PHP memory. Until that lands, callers must size the per-part cap
 * ({@see MultipartParser::MAX_PART_BYTES}) so the payload fits in RAM.
 *
 * The `index` field is the part's position in the source body,
 * exposed for callers that need to correlate parts (e.g. when
 * ordering matters or when pairing with out-of-band metadata).
 * The framework itself does not maintain a cleanup ledger.
 */
final readonly class FilePart
{
    public function __construct(
        public int $index,
        public string $fieldName,
        public string $clientName,
        public string $type,
        public string $payload,
    ) {
    }
}
