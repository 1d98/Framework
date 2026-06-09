<?php

declare(strict_types=1);

namespace Framework\Http\Multipart;

/**
 * Defang a client-supplied filename before it lands in
 * `UploadedFile::$name`. Strips CR/LF/NUL to defang header-injection
 * payloads like `evil\r\nSet-Cookie: x=y` (the browser-supplied
 * `filename=` is the only attacker-controlled string in a part that
 * user code may echo into a response header), then truncates to
 * {@see self::MAX_FILENAME_BYTES}.
 *
 * The original (unsanitized) value is NOT preserved — by the time
 * the filename reaches an `UploadedFile` it has already been
 * through `Content-Disposition` parsing, so the dangerous bytes
 * are gone. If you ever need the raw value for forensics, read the
 * raw request body before the middleware runs.
 */
final class FilenameSanitizer
{
    /**
     * Cap on the sanitized client-supplied filename. Strips
     * CR/LF/NUL first to defang CRLF-injection, then truncates to
     * this many bytes (ext4/NTFS limits and the de-facto
     * `<input type="file">` browser cap of 255 chars).
     */
    public const int MAX_FILENAME_BYTES = 255;

    public static function sanitize(string $clientName): string
    {
        $stripped = str_replace(["\r", "\n", "\0"], '', $clientName);
        if (strlen($stripped) > self::MAX_FILENAME_BYTES) {
            $stripped = substr($stripped, 0, self::MAX_FILENAME_BYTES);
        }
        return $stripped;
    }
}
