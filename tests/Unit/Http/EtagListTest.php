<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Http\Etag;
use Framework\Http\EtagList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EtagList::class)]
final class EtagListTest extends TestCase
{
    public function testParseNullYieldsEmpty(): void
    {
        $list = EtagList::parse(null);
        self::assertTrue($list->isEmpty());
        self::assertSame([], $list->all());
        self::assertFalse($list->wildcard);
    }

    public function testParseEmptyYieldsEmpty(): void
    {
        $list = EtagList::parse('   ');
        self::assertTrue($list->isEmpty());
    }

    public function testParseWildcard(): void
    {
        $list = EtagList::parse('*');
        self::assertTrue($list->wildcard);
        self::assertTrue($list->contains(new Etag('any-value')));
    }

    public function testParseSingleStrongEtag(): void
    {
        $list = EtagList::parse('"abc123"');
        self::assertCount(1, $list->all());
        self::assertSame('abc123', $list->all()[0]->value);
        self::assertFalse($list->all()[0]->weak);
    }

    public function testParseSingleWeakEtag(): void
    {
        $list = EtagList::parse('W/"abc123"');
        self::assertTrue($list->all()[0]->weak);
    }

    public function testParseMultipleMixed(): void
    {
        $list = EtagList::parse('"a", W/"b", "c"');
        self::assertCount(3, $list->all());
        $weakMatches = array_filter($list->all(), static fn(Etag $e): bool => $e->weak);
        self::assertCount(1, $weakMatches);
    }

    public function testContainsWeaklyMatchesAcrossWeakFlag(): void
    {
        $list = EtagList::parse('W/"abc"');
        self::assertTrue($list->contains(new Etag('abc')));
    }

    public function testContainsDoesNotMatchDifferentValue(): void
    {
        $list = EtagList::parse('"abc"');
        self::assertFalse($list->contains(new Etag('def')));
    }

    public function testContainsStrictRejectsWeak(): void
    {
        $list = EtagList::parse('W/"abc"');
        self::assertFalse($list->containsStrict(new Etag('abc')));
    }

    public function testContainsStrictAcceptsStrong(): void
    {
        $list = EtagList::parse('"abc"');
        self::assertTrue($list->containsStrict(new Etag('abc')));
    }

    public function testParseDropsMalformedTokens(): void
    {
        $list = EtagList::parse('"good", not-an-etag, "also-good"');
        self::assertCount(2, $list->all());
        self::assertSame('good', $list->all()[0]->value);
        self::assertSame('also-good', $list->all()[1]->value);
    }

    public function testParseDropsEmptyQuotes(): void
    {
        $list = EtagList::parse('""');
        self::assertTrue($list->isEmpty());
    }
}
