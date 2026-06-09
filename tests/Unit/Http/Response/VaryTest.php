<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Response;

use Framework\Http\Response\Vary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Vary::class)]
final class VaryTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function mergeProvider(): iterable
    {
        yield 'empty existing returns token'        => ['', 'Accept-Encoding', 'Accept-Encoding'];
        yield 'empty token returns existing'        => ['Origin, Accept-Encoding', '', 'Origin, Accept-Encoding'];
        yield 'whitespace token returns existing'   => ['Origin', '   ', 'Origin'];
        yield 'duplicate token is a no-op'          => ['Origin, Accept-Encoding', 'origin', 'Origin, Accept-Encoding'];
        yield 'duplicate token (case-insensitive)'  => ['Origin', 'ORIGIN', 'Origin'];
        yield 'duplicate token with whitespace'     => ['Origin', '  Origin  ', 'Origin'];
        yield 'multi-token existing, new appended'  => ['Origin, Accept-Encoding', 'User-Agent', 'Origin, Accept-Encoding, User-Agent'];
        yield 'leading/trailing whitespace trimmed' => ['  Origin  ,   Accept-Encoding  ', 'User-Agent', 'Origin  ,   Accept-Encoding, User-Agent'];
    }

    #[DataProvider('mergeProvider')]
    public function testMergeUsesDefaultSeparator(string $existing, string $token, string $expected): void
    {
        self::assertSame($expected, Vary::merge($existing, $token));
    }

    public function testDefaultSeparatorIsCommaSpace(): void
    {
        self::assertSame('Origin, Accept-Encoding', Vary::merge('Origin', 'Accept-Encoding'));
    }

    public function testCustomSeparatorIsHonored(): void
    {
        self::assertSame('Origin;Accept-Encoding', Vary::merge('Origin', 'Accept-Encoding', ';'));
    }

    public function testCustomSeparatorDeduplicates(): void
    {
        self::assertSame('Origin;Accept-Encoding', Vary::merge('Origin;Accept-Encoding', 'ORIGIN', ';'));
    }

    public function testMergeWithCustomSemicolonSeparatorAppendsMultipleTokens(): void
    {
        self::assertSame(
            'Origin;Accept-Encoding;User-Agent',
            Vary::merge('Origin;Accept-Encoding', 'User-Agent', ';'),
        );
    }

    public function testTokensWithEmptyStringReturnsEmptyList(): void
    {
        self::assertSame([], Vary::tokens(''));
    }

    public function testTokensWithSingleTokenReturnsThatToken(): void
    {
        self::assertSame(['Accept-Encoding'], Vary::tokens('Accept-Encoding'));
    }

    public function testTokensTrimsSurroundingWhitespaceFromEachToken(): void
    {
        self::assertSame(
            ['Origin', 'Accept-Encoding', 'User-Agent'],
            Vary::tokens('  Origin  ,   Accept-Encoding  ,  User-Agent  '),
        );
    }

    public function testTokensWithEmptySeparatorFallsBackToDefault(): void
    {
        self::assertSame(
            ['Origin', 'Accept-Encoding'],
            Vary::tokens('Origin, Accept-Encoding', ''),
        );
    }

    public function testMergeCaseInsensitiveDedupeKeepsOriginalSpelling(): void
    {
        self::assertSame(
            'Accept-Encoding',
            Vary::merge('Accept-Encoding', 'accept-encoding'),
            'case-insensitive dedupe must keep the FIRST occurrence spelling unchanged',
        );

        self::assertSame(
            'X-Foo-Bar',
            Vary::merge('X-Foo-Bar', 'X-FOO-BAR'),
        );
    }

    public function testTokensWithDuplicateTokensInInputReturnsAllOccurrences(): void
    {
        self::assertSame(
            ['Origin', 'Accept-Encoding', 'Origin'],
            Vary::tokens('Origin, Accept-Encoding, Origin'),
        );
    }

    public function testTokensDropsEmptySegmentsFromConsecutiveSeparators(): void
    {
        self::assertSame(
            ['Origin', 'Accept-Encoding'],
            Vary::tokens('Origin,,,Accept-Encoding,', ','),
        );
    }
}
