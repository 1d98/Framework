<?php

declare(strict_types=1);

namespace Framework\Http\Multipart;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Request\Request;

/**
 * Helpers for the request-level (envelope) view of a
 * `multipart/form-data` request: pull the boundary out of the
 * `Content-Type` header, and sanity-check `Content-Length` against
 * the buffered body.
 *
 * A `Content-Length` header that is present but non-numeric (e.g.
 * `Content-Length: 1.5GB_string` or `Content-Length: lots`) is
 * rejected: PHP's `is_numeric()` is the sole gate, so anything that
 * is not a valid PHP number (including scientific notation like
 * `1e10`, which IS numeric) is refused with a 400. This is part of
 * the same family of fixes as the "empty body + non-zero CL" check
 * below: a non-numeric CL is just as much a CL mismatch as a numeric
 * one that disagrees with the actual body length.
 *
 * These are pure of the parser core (which only walks the body
 * bytes) and pure of the filesystem — the middleware calls them
 * before / after handing the body to {@see MultipartParser}.
 */
final class MultipartEnvelope
{
    public static function extractBoundary(string $contentType): string
    {
        $segments = explode(';', $contentType);
        array_shift($segments);
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if (stripos($segment, 'boundary=') === 0) {
                $value = substr($segment, 9);
                if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
                    $value = substr($value, 1, -1);
                }
                return $value;
            }
        }
        return '';
    }

    /**
     * Enforce that the declared `Content-Length` matches the buffered
     * body byte-for-byte, even when both are zero. A non-zero declared
     * length with a zero-byte body is a clear mismatch (the SAPI
     * delivered less than declared) and must be rejected.
     *
     * A `Content-Length` that is present but not numeric (e.g. junk
     * like `1.5GB_string` or `lots`) is also rejected: there is no
     * defensible interpretation, so we refuse the request rather than
     * silently fall through. Whitespace-padded numerics (e.g. `  5  `)
     * and scientific notation (e.g. `1e10`) are accepted because PHP's
     * own `is_numeric()` considers them numeric — consistency with the
     * language's notion of "number" matters more than RFC strictness
     * here, since either way a non-integer value cannot match a body
     * length and would be caught by the equality check.
     *
     * SAPI cases where the multipart request is delivered with
     * `$_FILES` populated but a 0-byte body are handled by the
     * middleware (see {@see \Framework\Http\Middleware\MultipartBodyParser::process()},
     * which checks `SuperglobalFormReader::hasUploads()` before this
     * method is reached) and never reach here.
     */
    public static function assertContentLengthMatches(Request $request): void
    {
        $declared = $request->header('content-length');
        if ($declared === null) {
            return;
        }

        if (!ctype_digit($declared)) {
            throw new BadRequestHttpException(
                'Content-Length header is not a non-negative integer: ' . $declared,
            );
        }

        $actual = strlen($request->body);
        if ((int) $declared === $actual) {
            return;
        }

        throw new BadRequestHttpException(
            'Content-Length ' . $declared . ' does not match actual body length ' . $actual,
        );
    }
}
