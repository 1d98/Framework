<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Response;

use Framework\Http\Response\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Response::class)]
final class ResponseHeaderInjectionTest extends TestCase
{
    public function testWithHeaderRejectsCrlfInName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header name contains invalid character');

        Response::text('hi')->withHeader("X-Trace\r\nSet-Cookie", 'x');
    }

    public function testWithHeaderRejectsLfInName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header name contains invalid character');

        Response::text('hi')->withHeader("X-Trace\nX-Evil", 'x');
    }

    public function testWithHeaderRejectsCrInName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header name contains invalid character');

        Response::text('hi')->withHeader("X-Trace\rX-Evil", 'x');
    }

    public function testWithHeaderRejectsNulInName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header name contains invalid character');

        Response::text('hi')->withHeader("X-Trace\0X-Evil", 'x');
    }

    public function testWithHeaderRejectsColonInName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header name contains invalid character');

        Response::text('hi')->withHeader('X-Trace: pwn', 'x');
    }

    public function testWithHeaderRejectsCrlfInValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header value contains control character');

        Response::text('hi')->withHeader('X-Trace', "value\r\nX-Evil: pwn");
    }

    public function testWithHeaderRejectsLfInValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header value contains control character');

        Response::text('hi')->withHeader('X-Trace', "value\nX-Evil: pwn");
    }

    public function testWithHeaderRejectsCrInValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header value contains control character');

        Response::text('hi')->withHeader('X-Trace', "value\rX-Evil: pwn");
    }

    public function testWithHeaderRejectsNulInValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header value contains control character');

        Response::text('hi')->withHeader('X-Trace', "value\0with-null");
    }

    public function testWithHeaderAcceptsValidNameAndValue(): void
    {
        $response = Response::text('hi')->withHeader('X-Trace', 'valid');

        self::assertSame('valid', $response->headers['X-Trace']);
    }

    public function testWithHeaderAcceptsColonsInValue(): void
    {
        $response = Response::text('hi')->withHeader('X-Trace', 'value: with: colons');

        self::assertSame('value: with: colons', $response->headers['X-Trace']);
    }

    public function testWithHeaderAcceptsEmptyValue(): void
    {
        $response = Response::text('hi')->withHeader('X-Trace', '');

        self::assertSame('', $response->headers['X-Trace']);
    }

    public function testWithRequestIdRejectsCrlfInjectedId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header value contains control character');

        Response::text('hi')->withRequestId("abc\r\nSet-Cookie: pwn=1");
    }

    public function testWithHeadersRejectsCrlfInName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header name contains invalid character');

        Response::text('hi')->withHeaders(["X-Trace\r\nSet-Cookie" => 'x']);
    }

    public function testWithHeadersRejectsCrlfInValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header value contains control character');

        Response::text('hi')->withHeaders(['X-Trace' => "value\r\nX-Evil: pwn"]);
    }

    public function testWithHeadersAcceptsAllValidEntries(): void
    {
        $response = Response::text('hi')->withHeaders([
            'X-Trace' => 'abc',
            'X-Other' => 'value: with: colons',
            'X-Empty' => '',
        ]);

        self::assertSame('abc', $response->headers['X-Trace']);
        self::assertSame('value: with: colons', $response->headers['X-Other']);
        self::assertSame('', $response->headers['X-Empty']);
    }

    public function testToHeaderLinesRejectsCrlfInjectedNameFromDirectConstruction(): void
    {
        $response = new Response(200, 'hi', ["X-Trace\r\nSet-Cookie" => 'x']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header name contains invalid character');

        $response->toHeaderLines();
    }

    public function testToHeaderLinesRejectsCrlfInjectedValueFromDirectConstruction(): void
    {
        $response = new Response(200, 'hi', ['X-Trace' => "value\r\nX-Evil: pwn"]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header value contains control character');

        $response->toHeaderLines();
    }

    public function testRedirectRejectsCrlfInLocation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header value contains control character');

        Response::redirect("/login\r\nSet-Cookie: pwn=1");
    }

    public function testRedirectRejectsNulInLocation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header value contains control character');

        Response::redirect("/login\0evil");
    }

    public function testRedirectAcceptsValidLocation(): void
    {
        $response = Response::redirect('/dashboard', 302);

        self::assertSame(302, $response->status);
        self::assertSame('/dashboard', $response->headers['Location']);
    }

    public function testRedirectRejectsInvalidStatusCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Redirect status must be a 3xx redirect code');

        Response::redirect('/dashboard', 200);
    }
}
