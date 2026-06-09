<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Response;

use Framework\Http\Cookie\Cookie;
use Framework\Http\Response\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Response::class)]
final class ResponseCookieEmissionTest extends TestCase
{
    public function testZeroCookiesEmitsNoSetCookieLines(): void
    {
        $response = Response::text('hi');

        $cookieLines = $this->setCookieLines($response->toHeaderLines());

        self::assertSame([], $cookieLines);
    }

    public function testSingleCookieEmitsExactlyOneSetCookieLine(): void
    {
        $response = Response::text('hi')
            ->withCookie(new Cookie(name: 'session', value: 'abc', sameSite: 'Lax'));

        $cookieLines = $this->setCookieLines($response->toHeaderLines());

        self::assertCount(1, $cookieLines);
        self::assertStringContainsString('session=abc', $cookieLines[0]);
    }

    public function testThreeCookiesEmitThreeSeparateSetCookieLines(): void
    {
        $response = Response::text('hi')
            ->withCookie(new Cookie(name: 'a', value: '1', sameSite: 'Lax'))
            ->withCookie(new Cookie(name: 'b', value: '2', sameSite: 'Lax'))
            ->withCookie(new Cookie(name: 'c', value: '3', sameSite: 'Lax'));

        $cookieLines = $this->setCookieLines($response->toHeaderLines());

        self::assertCount(3, $cookieLines);
        self::assertStringContainsString('a=1', $cookieLines[0]);
        self::assertStringContainsString('b=2', $cookieLines[1]);
        self::assertStringContainsString('c=3', $cookieLines[2]);
    }

    public function testNoSetCookieLineIsCommaJoined(): void
    {
        $response = Response::text('hi')
            ->withCookie(new Cookie(name: 'a', value: '1', sameSite: 'Lax'))
            ->withCookie(new Cookie(name: 'b', value: '2', sameSite: 'Lax'));

        foreach ($response->toHeaderLines() as $line) {
            if (str_starts_with($line, 'Set-Cookie:')) {
                self::assertStringNotContainsString(
                    ',',
                    substr($line, strlen('Set-Cookie:')),
                    'Each Set-Cookie header must be a single cookie, not a comma-joined list (RFC 6265)',
                );
            }
        }
    }

    public function testNamedHeadersAreEmittedAlongsideCookies(): void
    {
        $response = Response::text('hi', 201)
            ->withHeader('X-Trace-Id', 'xyz')
            ->withCookie(new Cookie(name: 'a', value: '1', sameSite: 'Lax'));

        $lines = $response->toHeaderLines();

        self::assertContains('X-Trace-Id: xyz', $lines);
        self::assertCount(1, $this->setCookieLines($lines));
    }

    public function testToHeaderLinesEmitsHeadersBeforeCookies(): void
    {
        $response = (new Response(200, 'hi', ['X-Trace' => '1']))
            ->withCookie(new Cookie(name: 'a', value: '1', sameSite: 'Lax'))
            ->withHeader('X-Other', '2');

        $lines = $response->toHeaderLines();

        self::assertSame('X-Trace: 1', $lines[0]);
        self::assertSame('X-Other: 2', $lines[1]);
        self::assertStringStartsWith('Set-Cookie: a=1', $lines[2]);
    }

    public function testResponseWithNoHeadersAndNoCookiesEmitsNoLines(): void
    {
        $response = new Response(status: 204);

        self::assertSame([], $response->toHeaderLines());
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function setCookieLines(array $lines): array
    {
        return array_values(array_filter(
            $lines,
            static fn (string $line): bool => str_starts_with($line, 'Set-Cookie:'),
        ));
    }
}
