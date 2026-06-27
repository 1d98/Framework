<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Multipart;

use Framework\Http\Multipart\FilenameSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FilenameSanitizer::class)]
final class FilenameSanitizerTest extends TestCase
{
    public function testPlainAsciiPassesThrough(): void
    {
        self::assertSame('hello.txt', FilenameSanitizer::sanitize('hello.txt'));
    }

    public function testStripsCrlfFromName(): void
    {
        // Defang CRLF header injection — the filename ends up in
        // `Content-Disposition: ...filename="..."` echoed back to the
        // client, where a stray CR/LF would let an attacker add an
        // arbitrary response header.
        $result = FilenameSanitizer::sanitize("evil\r\nSet-Cookie: x=y.txt");
        self::assertStringNotContainsString("\r", $result);
        self::assertStringNotContainsString("\n", $result);
        self::assertStringContainsString('Set-Cookie: x=y.txt', $result);
    }

    public function testStripsLfOnlyFromName(): void
    {
        $result = FilenameSanitizer::sanitize("evil\nX-Evil: pwn.txt");
        // The LF is stripped (the actual injection vector); the rest
        // of the payload — letters, colons, spaces — survives as part
        // of the filename. The framework does NOT try to be smart about
        // what comes after the LF; it just defangs the byte that would
        // let an attacker add a new header line.
        self::assertStringNotContainsString("\n", $result);
        self::assertStringContainsString('X-Evil', $result);
    }

    public function testStripsCrOnlyFromName(): void
    {
        $result = FilenameSanitizer::sanitize("evil\rX-Evil: pwn.txt");
        self::assertStringNotContainsString("\r", $result);
        self::assertStringContainsString('X-Evil', $result);
    }

    public function testStripsNulFromName(): void
    {
        // NUL is also a header-injection / truncation vector in some
        // downstream consumers (C library string handling). Strip it.
        $result = FilenameSanitizer::sanitize("prefix\0suffix.txt");
        self::assertStringNotContainsString("\0", $result);
        self::assertSame('prefixsuffix.txt', $result);
    }

    public function testStripsPosixPathSeparators(): void
    {
        // `/etc/passwd` should never survive a round-trip — the
        // sanitizer collapses all slashes so the directory component
        // is gone before the reserved-basename / truncation steps.
        self::assertSame('etcpasswd', FilenameSanitizer::sanitize('/etc/passwd'));
    }

    public function testStripsWindowsPathSeparators(): void
    {
        // `\` is the Windows path separator. After stripping path
        // separators (both `/` and `\`), the leading dots are ltrimmed
        // off — so `..\..\windows\system32` collapses all the way to
        // `windowssystem32`, with no leading dots and no path component.
        self::assertSame('windowssystem32', FilenameSanitizer::sanitize('..\\..\\windows\\system32'));
    }

    public function testStripsLeadingDots(): void
    {
        // Defang `..` traversal fragments AND hidden-file semantics on
        // POSIX (`/etc/foo` → `..foo` → `.foo` would otherwise hide the
        // upload from `ls`). Leading dots are stripped.
        self::assertSame('foo.txt', FilenameSanitizer::sanitize('..foo.txt'));
    }

    public function testStripsMultipleLeadingDots(): void
    {
        self::assertSame('file.txt', FilenameSanitizer::sanitize('...file.txt'));
    }

    public function testReservedBasenameConIsReplacedByExtension(): void
    {
        // Windows reserved name `CON` — the basename is dropped, the
        // extension is preserved with original case. So `CON.txt`
        // collapses to `.txt` (a benign dotfile) instead of refusing to
        // upload.
        self::assertSame('.txt', FilenameSanitizer::sanitize('CON.txt'));
    }

    public function testReservedBasenameConTxtWithUppercaseExtensionIsPreserved(): void
    {
        // Case-preserving extension: `con.TXT` → `.TXT` (the user
        // keeps the suffix they actually wrote).
        self::assertSame('.TXT', FilenameSanitizer::sanitize('con.TXT'));
    }

    public function testReservedBasenameNulWithNoExtensionFallsBackToFile(): void
    {
        // Reserved basename with no extension → empty sanitized value
        // → fall back to the default `'file'`.
        self::assertSame('file', FilenameSanitizer::sanitize('NUL'));
    }

    public function testReservedBasenamePrnWithoutExtensionFallsBackToFile(): void
    {
        self::assertSame('file', FilenameSanitizer::sanitize('PRN'));
    }

    public function testReservedBasenameCom1WithoutExtensionFallsBackToFile(): void
    {
        self::assertSame('file', FilenameSanitizer::sanitize('COM1'));
    }

    public function testReservedBasenameLpt9WithoutExtensionFallsBackToFile(): void
    {
        self::assertSame('file', FilenameSanitizer::sanitize('LPT9'));
    }

    public function testReservedBasenameIsCaseInsensitive(): void
    {
        // The reserved-name check is case-insensitive (Windows treats
        // `con.txt` and `CON.TXT` the same).
        self::assertSame('.dat', FilenameSanitizer::sanitize('Con.dat'));
        self::assertSame('.dat', FilenameSanitizer::sanitize('cOn.dat'));
    }

    public function testNonReservedBasenameIsNotStripped(): void
    {
        // `CONTAINS` is NOT a reserved Windows device name — only the
        // exact 22 names listed in `FilenameSanitizer::RESERVED_BASENAMES`
        // trigger the collapse.
        self::assertSame('contains.txt', FilenameSanitizer::sanitize('contains.txt'));
    }

    public function testTruncatesToMaxFilenameBytes(): void
    {
        $longName = str_repeat('a', 500) . '.txt';
        $sanitized = FilenameSanitizer::sanitize($longName);

        self::assertSame(FilenameSanitizer::MAX_FILENAME_BYTES, strlen($sanitized));
        // Truncation happens AFTER sanitization; a 200-byte prefix of
        // all-`a` characters is the expected outcome.
        self::assertSame(str_repeat('a', FilenameSanitizer::MAX_FILENAME_BYTES), $sanitized);
    }

    public function testExactlyMaxFilenameBytesIsKept(): void
    {
        $exactName = str_repeat('b', FilenameSanitizer::MAX_FILENAME_BYTES);
        self::assertSame($exactName, FilenameSanitizer::sanitize($exactName));
    }

    public function testEmptyAfterSanitizationFallsBackToFile(): void
    {
        // Every character is a separator / dot / control char → empty
        // string → fall back to the benign default.
        self::assertSame('file', FilenameSanitizer::sanitize('....'));
        self::assertSame('file', FilenameSanitizer::sanitize(''));
        self::assertSame('file', FilenameSanitizer::sanitize("\r\n"));
        self::assertSame('file', FilenameSanitizer::sanitize('///'));
        self::assertSame('file', FilenameSanitizer::sanitize("\0\0\0"));
    }

    public function testMaxFilenameBytesIs200(): void
    {
        // Pin the documented constant — changing it would be a breaking
        // contract change for any consumer that persists sanitized
        // names to a column sized to the prior cap.
        self::assertSame(200, FilenameSanitizer::MAX_FILENAME_BYTES);
    }

    public function testPathTraversalCollapsesToFlatName(): void
    {
        // Classic `../../etc/cron.d/backdoor` payload — the slashes
        // collapse first, the leading dots strip next, leaving the
        // attacker with a flat filename rather than a path.
        self::assertSame('etccron.dbackdoor', FilenameSanitizer::sanitize('../../etc/cron.d/backdoor'));
    }

    public function testMixedSeparatorsCollapsed(): void
    {
        // Mixed POSIX + Windows separators in one name: everything
        // collapses, leaving only the basename characters.
        self::assertSame('evilbasename', FilenameSanitizer::sanitize('\\evil/basename'));
    }

    public function testReservedBasenameSurvivingAfterStrippingDotsFallsBack(): void
    {
        // `..CON.txt` — leading dots are stripped FIRST, leaving
        // `CON.txt`, which is a reserved basename → drop the basename,
        // keep the extension.
        self::assertSame('.txt', FilenameSanitizer::sanitize('..CON.txt'));
    }

    public function testReservedBasenameAfterPathStripFallsBack(): void
    {
        // `/etc/CON.txt` — slashes strip first (yields `etcCON.txt`),
        // then basename check on `etcCON` (not a reserved device
        // name on its own) — the reserved-name check is on the
        // post-sanitization basename, which after path-stripping is
        // no longer the bare `CON`. This test pins that behaviour so
        // a future refactor of the ordering does not silently widen
        // the reserved-name detection.
        self::assertSame('etcCON.txt', FilenameSanitizer::sanitize('/etc/CON.txt'));
    }
}