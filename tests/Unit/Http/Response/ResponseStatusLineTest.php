<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Response;

use Framework\Http\Cookie\Cookie;
use Framework\Http\Response\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(Response::class)]
final class ResponseStatusLineTest extends TestCase
{
    public function testConstructorAcceptsValidReasonPhrase(): void
    {
        $response = new Response(200, '', [], [], 'OK');

        self::assertSame(200, $response->status);
        self::assertSame('OK', $response->reasonPhrase);
    }

    public function testConstructorAcceptsEmptyReasonPhrase(): void
    {
        $response = new Response(200, '', [], [], '');

        self::assertSame('', $response->reasonPhrase);
    }

    public function testConstructorAcceptsNullReasonPhrase(): void
    {
        $response = new Response(200, '', [], [], null);

        self::assertNull($response->reasonPhrase);
    }

    public function testConstructorAcceptsApostropheInReason(): void
    {
        $response = new Response(418, '', [], [], "I'm a teapot");

        self::assertSame("I'm a teapot", $response->reasonPhrase);
    }

    public function testConstructorRejectsCrlfInReasonPhrase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Status reason phrase contains CRLF');

        new Response(200, '', [], [], "OK\r\nSet-Cookie: pwn=1");
    }

    public function testConstructorRejectsLfInReasonPhrase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Status reason phrase contains CRLF');

        new Response(200, '', [], [], "OK\nX-Evil: pwn");
    }

    public function testConstructorRejectsCrInReasonPhrase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Status reason phrase contains CRLF');

        new Response(200, '', [], [], "OK\rX-Evil: pwn");
    }

    public function testWithStatusRejectsCrlfInReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Status reason phrase contains CRLF');

        Response::text('hi')->withStatus(200, "OK\r\nSet-Cookie: pwn=1");
    }

    public function testWithStatusRejectsLfInReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Status reason phrase contains CRLF');

        Response::text('hi')->withStatus(200, "OK\nX-Evil: pwn");
    }

    public function testWithStatusRejectsCrInReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Status reason phrase contains CRLF');

        Response::text('hi')->withStatus(200, "OK\rX-Evil: pwn");
    }

    public function testWithStatusAcceptsEmptyReason(): void
    {
        $response = Response::text('hi')->withStatus(204, '');

        self::assertSame(204, $response->status);
        self::assertSame('', $response->reasonPhrase);
    }

    public function testWithStatusAcceptsNullReason(): void
    {
        $response = Response::text('hi')->withStatus(200, null);

        self::assertSame(200, $response->status);
        self::assertNull($response->reasonPhrase);
    }

    public function testWithStatusNullReasonResolvesToDefaultInStatusLine(): void
    {
        $response = Response::text('hi')->withStatus(200, null);

        $line = (new ReflectionMethod(Response::class, 'buildStatusLine'))
            ->invoke($response);

        self::assertSame('HTTP/1.1 200 OK', $line);
    }

    public function testWithStatusApostropheInReasonIsAllowed(): void
    {
        $response = Response::text('hi')->withStatus(418, "I'm a teapot");

        self::assertSame(418, $response->status);
        self::assertSame("I'm a teapot", $response->reasonPhrase);

        $line = (new ReflectionMethod(Response::class, 'buildStatusLine'))
            ->invoke($response);

        self::assertSame("HTTP/1.1 418 I'm a teapot", $line);
    }

    public function testWithStatusEmptyReasonProducesStatusLineWithoutReason(): void
    {
        $response = Response::text('hi')->withStatus(204, '');

        $line = (new ReflectionMethod(Response::class, 'buildStatusLine'))
            ->invoke($response);

        self::assertSame('HTTP/1.1 204', $line);
    }

    public function testSetStatusRejectsCrlfInReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Status reason phrase contains CRLF');

        Response::text('hi')->setStatus(200, "OK\r\nSet-Cookie: pwn=1");
    }

    public function testSetStatusAcceptsValidReason(): void
    {
        $response = Response::text('hi')->setStatus(201, 'Created');

        self::assertSame(201, $response->status);
        self::assertSame('Created', $response->reasonPhrase);
    }

    public function testWithHeaderPreservesReasonPhrase(): void
    {
        $response = Response::text('hi')
            ->withStatus(418, "I'm a teapot")
            ->withHeader('X-Trace', 'abc');

        self::assertSame("I'm a teapot", $response->reasonPhrase);
        self::assertSame('abc', $response->headers['X-Trace']);
    }

    public function testWithCookiePreservesReasonPhrase(): void
    {
        $response = Response::text('hi')
            ->withStatus(418, "I'm a teapot")
            ->withCookie(new Cookie(name: 'a', value: '1'));

        self::assertSame("I'm a teapot", $response->reasonPhrase);
        self::assertCount(1, $response->cookies());
    }

    public function testSendRejectsCrlfInjectedStatusLineAsDefenseInDepth(): void
    {
        $method = new ReflectionMethod(Response::class, 'assertNoHeaderLineInjection');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header line contains CRLF');

        $method->invoke(null, "HTTP/1.1 200 OK\r\nSet-Cookie: pwn=1");
    }

    public function testResponseWithMaliciousReasonReturns500InsteadOfInjection(): void
    {
        $thrown = null;
        try {
            Response::text('hi')->withStatus(200, "OK\r\nSet-Cookie: pwn=1");
        } catch (InvalidArgumentException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown);
        self::assertStringContainsString('Status reason phrase contains CRLF', $thrown->getMessage());
    }
}
