<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Cookie;

use Framework\Http\Cookie\Cookie;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cookie::class)]
final class CookieTest extends TestCase
{
    public function testDefaultValuesAreSafeAndSane(): void
    {
        $cookie = new Cookie(name: 'session_id', value: 'abc123');

        self::assertSame('session_id', $cookie->name);
        self::assertSame('abc123', $cookie->value);
        self::assertSame(0, $cookie->expiresAt);
        self::assertSame('/', $cookie->path);
        self::assertNull($cookie->domain);
        self::assertFalse($cookie->secure);
        self::assertTrue($cookie->httpOnly);
        self::assertSame('Lax', $cookie->sameSite);
    }

    public function testToHeaderValueSessionCookie(): void
    {
        $cookie = new Cookie(name: 'session_id', value: 'abc123');

        self::assertSame('session_id=abc123; HttpOnly; SameSite=Lax', $cookie->toHeaderValue());
    }

    public function testToHeaderValueWithFullAttributes(): void
    {
        $expires = 1_748_000_000;
        $cookie = new Cookie(
            name: 'session_id',
            value: 'abc123',
            expiresAt: $expires,
            path: '/',
            domain: 'example.com',
            secure: true,
            httpOnly: true,
            sameSite: 'Lax',
        );

        $value = $cookie->toHeaderValue();
        self::assertStringStartsWith('session_id=abc123; ', $value);
        self::assertStringContainsString('Expires=' . gmdate('D, d M Y H:i:s', $expires) . ' GMT', $value);
        self::assertStringContainsString('Max-Age=' . ($expires - time()), $value);
        self::assertStringContainsString('Domain=example.com', $value);
        self::assertStringContainsString('Secure', $value);
        self::assertStringContainsString('HttpOnly', $value);
        self::assertStringContainsString('SameSite=Lax', $value);
        self::assertStringNotContainsString('Path=', $value, 'Default path / must be omitted');
    }

    public function testToHeaderValueOmitsHttpOnlyWhenDisabled(): void
    {
        $cookie = new Cookie(name: 'x', value: 'y', httpOnly: false);

        self::assertStringNotContainsString('HttpOnly', $cookie->toHeaderValue());
    }

    public function testToHeaderValueOmitsSecureWhenDisabled(): void
    {
        $cookie = new Cookie(name: 'x', value: 'y', secure: false);

        self::assertStringNotContainsString('Secure', $cookie->toHeaderValue());
    }

    public function testToHeaderValueOmitsDomainWhenNull(): void
    {
        $cookie = new Cookie(name: 'x', value: 'y', domain: null);

        self::assertStringNotContainsString('Domain=', $cookie->toHeaderValue());
    }

    public function testToHeaderValueIncludesPathWhenNonDefault(): void
    {
        $cookie = new Cookie(name: 'x', value: 'y', path: '/api');

        self::assertStringContainsString('Path=/api', $cookie->toHeaderValue());
    }

    public function testToHeaderValueWithSameSiteStrict(): void
    {
        $cookie = new Cookie(name: 'x', value: 'y', sameSite: 'Strict');

        self::assertStringContainsString('SameSite=Strict', $cookie->toHeaderValue());
    }

    public function testToHeaderValueWithSameSiteNone(): void
    {
        $cookie = new Cookie(name: 'x', value: 'y', sameSite: 'None', secure: true);

        self::assertStringContainsString('SameSite=None', $cookie->toHeaderValue());
        self::assertStringContainsString('Secure', $cookie->toHeaderValue());
    }

    public function testConstructorThrowsOnInvalidSameSite(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sameSite must be "Lax", "Strict", or "None"');

        new Cookie(name: 'x', value: 'y', sameSite: 'Invalid');
    }

    public function testConstructorThrowsWhenSameSiteNoneWithoutSecure(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SameSite=None');
        $this->expectExceptionMessage('Secure');

        new Cookie(name: 'x', value: 'y', sameSite: 'None', secure: false);
    }

    public function testConstructorThrowsWhenSameSiteNoneWithoutSecureUsesCookieName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'session_id'");

        new Cookie(name: 'session_id', value: 'abc', sameSite: 'None', secure: false);
    }

    public function testConstructorAcceptsLaxWithoutSecure(): void
    {
        $cookie = new Cookie(name: 'x', value: 'y', sameSite: 'Lax', secure: false);

        self::assertSame('Lax', $cookie->sameSite);
        self::assertFalse($cookie->secure);
    }

    public function testConstructorAcceptsStrictWithoutSecure(): void
    {
        $cookie = new Cookie(name: 'x', value: 'y', sameSite: 'Strict', secure: false);

        self::assertSame('Strict', $cookie->sameSite);
        self::assertFalse($cookie->secure);
    }

    public function testConstructorAcceptsNoneWithSecure(): void
    {
        $cookie = new Cookie(name: 'x', value: 'y', sameSite: 'None', secure: true);

        self::assertSame('None', $cookie->sameSite);
        self::assertTrue($cookie->secure);
    }

    public function testConstructorAcceptsLaxWithSecure(): void
    {
        $cookie = new Cookie(name: 'x', value: 'y', sameSite: 'Lax', secure: true);

        self::assertSame('Lax', $cookie->sameSite);
        self::assertTrue($cookie->secure);
    }

    public function testConstructorAcceptsStrictWithSecure(): void
    {
        $cookie = new Cookie(name: 'x', value: 'y', sameSite: 'Strict', secure: true);

        self::assertSame('Strict', $cookie->sameSite);
        self::assertTrue($cookie->secure);
    }
}
