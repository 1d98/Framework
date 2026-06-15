<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Http\Etag;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Etag::class)]
final class EtagTest extends TestCase
{
    public function testStrongEtagHeaderFormat(): void
    {
        $etag = new Etag('abc123');
        self::assertSame('"abc123"', $etag->toHeader());
        self::assertFalse($etag->weak);
    }

    public function testWeakEtagHeaderFormat(): void
    {
        $etag = new Etag('abc123', weak: true);
        self::assertSame('W/"abc123"', $etag->toHeader());
        self::assertTrue($etag->weak);
    }

    public function testEmptyValueRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Etag('');
    }

    public function testQuoteInValueRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Etag('abc"123');
    }

    public function testNulByteInValueRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Etag("abc\x00def");
    }

    public function testWeakComparisonIgnoresWeakFlag(): void
    {
        $strong = new Etag('abc');
        $weak = new Etag('abc', weak: true);
        self::assertTrue($strong->weakMatches($weak));
        self::assertTrue($weak->weakMatches($strong));
    }

    public function testStrongComparisonRejectsWeakPair(): void
    {
        $strong = new Etag('abc');
        $weak = new Etag('abc', weak: true);
        self::assertFalse($strong->strongMatches($weak));
        self::assertFalse($weak->strongMatches($strong));
    }

    public function testStrongComparisonAcceptsMatchingStrongPair(): void
    {
        $a = new Etag('abc');
        $b = new Etag('abc');
        self::assertTrue($a->strongMatches($b));
    }

    public function testStrongComparisonRejectsDifferentValue(): void
    {
        $a = new Etag('abc');
        $b = new Etag('def');
        self::assertFalse($a->strongMatches($b));
    }
}
