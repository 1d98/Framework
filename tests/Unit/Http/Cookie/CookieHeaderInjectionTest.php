<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Cookie;

use Framework\Http\Cookie\Cookie;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cookie::class)]
final class CookieHeaderInjectionTest extends TestCase
{
    public function testConstructorRejectsCrlfInValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cookie value contains CRLF');

        new Cookie(name: 'csrf', value: "a\r\nSet-Cookie: pwn=1");
    }

    public function testConstructorRejectsCrlfInName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cookie name contains CRLF');

        new Cookie(name: "csrf\r\nX-Evil: pwn", value: 'x');
    }

    public function testConstructorRejectsCrlfInPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cookie path contains CRLF');

        new Cookie(name: 'csrf', value: 'x', path: "/api\r\nX-Evil: pwn");
    }

    public function testConstructorRejectsCrlfInDomain(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cookie domain contains CRLF');

        new Cookie(name: 'csrf', value: 'x', domain: "evil.com\r\nX-Evil: pwn");
    }

    public function testConstructorAcceptsValidNameAndValue(): void
    {
        $cookie = new Cookie(name: 'csrf', value: 'x');

        self::assertSame('csrf', $cookie->name);
        self::assertSame('x', $cookie->value);
    }

    public function testToHeaderValueRejectsCrlfInNameAsDefenseInDepth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cookie name contains CRLF');

        (new Cookie(name: "csrf\r\nX-Evil: pwn", value: 'x'))->toHeaderValue();
    }

    public function testToHeaderValueRejectsCrlfInValueAsDefenseInDepth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cookie value contains CRLF');

        (new Cookie(name: 'csrf', value: "a\r\nSet-Cookie: pwn=1"))->toHeaderValue();
    }

    public function testToHeaderValueRejectsCrlfInPathAsDefenseInDepth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cookie path contains CRLF');

        (new Cookie(name: 'csrf', value: 'x', path: "/api\r\nX-Evil: pwn"))->toHeaderValue();
    }

    public function testToHeaderValueRejectsCrlfInDomainAsDefenseInDepth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cookie domain contains CRLF');

        (new Cookie(name: 'csrf', value: 'x', domain: "evil.com\r\nX-Evil: pwn"))->toHeaderValue();
    }

    public function testToHeaderValueAcceptsValidCookie(): void
    {
        $cookie = new Cookie(name: 'csrf', value: 'x');

        self::assertSame('csrf=x; HttpOnly; SameSite=Lax', $cookie->toHeaderValue());
    }

    public function testConstructorRejectsLfOnlyInName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cookie name contains CRLF');

        new Cookie(name: "csrf\nX-Evil: pwn", value: 'x');
    }

    public function testConstructorRejectsCrOnlyInValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cookie value contains CRLF');

        new Cookie(name: 'csrf', value: "a\rSet-Cookie: pwn=1");
    }
}
