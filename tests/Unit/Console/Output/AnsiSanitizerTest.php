<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console\Output;

use Framework\Console\Output\AnsiSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnsiSanitizer::class)]
final class AnsiSanitizerTest extends TestCase
{
    public function testStripsSgrColor(): void
    {
        self::assertSame('hello world', AnsiSanitizer::sanitize("\x1B[31mhello world\x1B[0m"));
    }

    public function testStrips256Color(): void
    {
        self::assertSame('text', AnsiSanitizer::sanitize("\x1B[38;5;202mtext\x1B[0m"));
    }

    public function testStripsTrueColor(): void
    {
        self::assertSame('text', AnsiSanitizer::sanitize("\x1B[38;2;255;0;0mtext\x1B[0m"));
    }

    public function testStripsCursorMovement(): void
    {
        self::assertSame('abc', AnsiSanitizer::sanitize("a\x1B[2J\x1B[Hb\x1B[10Cc"));
    }

    public function testStripsEraseLine(): void
    {
        self::assertSame('keep', AnsiSanitizer::sanitize("keep\x1B[K"));
    }

    public function testStripsOscTitle(): void
    {
        self::assertSame('ab', AnsiSanitizer::sanitize("a\x1B]0;evil\x07b"));
    }

    public function testStripsOscHyperlink(): void
    {
        self::assertSame('click', AnsiSanitizer::sanitize("\x1B]8;;https://evil\x1B\\click\x1B]8;;\x1B\\"));
    }

    public function testStripsTwoByteEscapes(): void
    {
        self::assertSame('reset', AnsiSanitizer::sanitize("\x1Bcreset"));
    }

    public function testStripsBell(): void
    {
        self::assertSame('a', AnsiSanitizer::sanitize("a\x07"));
    }

    public function testStripsBackspace(): void
    {
        self::assertSame('a', AnsiSanitizer::sanitize("a\x08"));
    }

    public function testStripsNul(): void
    {
        self::assertSame('ab', AnsiSanitizer::sanitize("a\x00b"));
    }

    public function testStripsFormFeed(): void
    {
        self::assertSame('a', AnsiSanitizer::sanitize("a\x0C"));
    }

    public function testPreservesTab(): void
    {
        self::assertSame("a\tb", AnsiSanitizer::sanitize("a\tb"));
    }

    public function testPreservesNewline(): void
    {
        self::assertSame("a\nb", AnsiSanitizer::sanitize("a\nb"));
    }

    public function testPreservesCarriageReturn(): void
    {
        self::assertSame("a\rb", AnsiSanitizer::sanitize("a\rb"));
    }

    public function testLeavesPlainTextAlone(): void
    {
        self::assertSame('plain ASCII', AnsiSanitizer::sanitize('plain ASCII'));
    }

    public function testLeavesUnicodeAlone(): void
    {
        self::assertSame('héllo wörld 🌍', AnsiSanitizer::sanitize('héllo wörld 🌍'));
    }

    public function testHandlesNestedSequences(): void
    {
        self::assertSame('a', AnsiSanitizer::sanitize("\x1B[1m\x1B[31ma\x1B[0m"));
    }

    public function testStripsDecKeypadApplicationMode(): void
    {
        self::assertSame('after', AnsiSanitizer::sanitize("\x1B=after"));
    }

    public function testStripsDecKeypadNumericMode(): void
    {
        self::assertSame('after', AnsiSanitizer::sanitize("\x1B>after"));
    }

    public function testDcsWithoutStringTerminatorIsStillStripped(): void
    {
        self::assertSame('', AnsiSanitizer::sanitize("\x1BPsome-payload-no-terminator"));
    }

    public function testSosWithoutStringTerminatorIsStillStripped(): void
    {
        self::assertSame('', AnsiSanitizer::sanitize("\x1BXsecret"));
    }

    public function testPmWithoutStringTerminatorIsStillStripped(): void
    {
        self::assertSame('', AnsiSanitizer::sanitize("\x1B^priv-msg"));
    }

    public function testApcWithoutStringTerminatorIsStillStripped(): void
    {
        self::assertSame('', AnsiSanitizer::sanitize("\x1B_app-tag"));
    }

    public function testDcsWithStringTerminatorStripsPayload(): void
    {
        self::assertSame('visible', AnsiSanitizer::sanitize("\x1BPsome-payload\x1B\\visible"));
    }

    public function testOscWithoutTerminatorIsStillStripped(): void
    {
        self::assertSame('', AnsiSanitizer::sanitize("\x1B]0;evil-title-no-terminator"));
    }

    public function testStripsEightBitStringTerminator(): void
    {
        self::assertSame('after', AnsiSanitizer::sanitize("\x1BPpayload\x9Cafter"));
    }

    public function testStripsEightBitCsi(): void
    {
        self::assertSame('text', AnsiSanitizer::sanitize("\x9B31mtext\x9Bm"));
    }

    public function testStripsEightBitCsiWithParameters(): void
    {
        self::assertSame('text', AnsiSanitizer::sanitize("\x9B38;5;202mtext\x9Bm"));
    }
}
