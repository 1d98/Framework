<?php

declare(strict_types=1);

namespace Framework\Http\Multipart;

/**
 * Defang a client-supplied filename before it lands in
 * `UploadedFile::$name`. Strips CR/LF/NUL to defang header-injection
 * payloads like `evil\r\nSet-Cookie: x=y` (the browser-supplied
 * `filename=` is the only attacker-controlled string in a part that
 * user code may echo into a response header), strips leading and
 * trailing whitespace so the reserved-name check below cannot be
 * bypassed by `"   CON.txt   "` (a leading space would survive
 * `strtoupper(...) !== 'CON'` and slip through), then strips path
 * separators and reserved-Windows basenames to defang
 * path-traversal payloads like `../../etc/cron.d/backdoor` or
 * `CON.txt`, then truncates to {@see self::MAX_FILENAME_BYTES}.
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
     * Cap on the sanitized client-supplied filename, in bytes.
     * Strips CR/LF/NUL, path separators, and reserved basenames
     * first to defang CRLF-injection and path-traversal, then
     * truncates to this many bytes. The value is intentionally
     * smaller than the de-facto `<input type="file">` browser cap
     * (255 chars) and well below the per-component ext4 / NTFS
     * limit so that the sanitized name survives round-trips on
     * every supported filesystem.
     */
    public const int MAX_FILENAME_BYTES = 200;

    /**
     * Windows reserved basenames — comparing against `pathinfo()['filename']`
     * case-insensitively. If a sanitized upload lands here, Windows refuses
     * to create the file and the server-side write throws. We strip the
     * basename and keep only the extension (if any), or fall back to `'file'`.
     *
     * Source: https://learn.microsoft.com/en-us/windows/win32/fileio/naming-a-file
     *
     * @var list<string>
     */
    private const array RESERVED_BASENAMES = [
        'CON', 'PRN', 'AUX', 'NUL',
        'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
    ];

    public static function sanitize(string $clientName): string
    {
        // 1. Strip CR/LF/NUL — defang CRLF / NUL header injection.
        $stripped = str_replace(["\r", "\n", "\0"], '', $clientName);

        // 1b. Strip leading/trailing whitespace. Whitespace is a no-op for
        //     filesystem safety on most OSes, but `pathinfo(...)['filename']`
        //     preserves it, and a leading space would defeat the case-
        //     insensitive reserved-name check below (`"   CON"` uppercased
        //     is still not `"CON"`). Trim BEFORE the reserved check.
        $stripped = trim($stripped);

        // 2. Strip path separators — both POSIX `/` and Windows `\`. After
        //    this, no directory component can survive, so `../../etc/passwd`
        //    collapses to `..etcpasswd` before step 3.
        $stripped = str_replace(['/', '\\'], '', $stripped);

        // 3. Strip leading dots — defang `..` traversal fragments and
        //    hidden-file semantics (`/etc/foo` → `..foo` → `.foo`).
        $stripped = ltrim($stripped, '.');

        // 4. If the remaining basename is a Windows reserved device name,
        //    drop it. We compare case-insensitively against the FILENAME
        //    (without extension); the EXTENSION is taken from the
        //    ORIGINAL-case input so the user keeps case-faithful suffixes
        //    like `con.TXT` → `TXT`.
        $pathInfo = pathinfo($stripped);
        $baseUpper = strtoupper($pathInfo['filename'] ?? '');
        if ($baseUpper !== '' && in_array($baseUpper, self::RESERVED_BASENAMES, true)) {
            $originalInfo = pathinfo($clientName);
            $ext = isset($originalInfo['extension']) ? '.' . $originalInfo['extension'] : '';
            $stripped = $ext;
        }

        // 5. Truncate to MAX_FILENAME_BYTES.
        if (strlen($stripped) > self::MAX_FILENAME_BYTES) {
            $stripped = substr($stripped, 0, self::MAX_FILENAME_BYTES);
        }

        // 6. Fall back to a benign default if everything was stripped.
        if ($stripped === '') {
            return 'file';
        }

        return $stripped;
    }
}
