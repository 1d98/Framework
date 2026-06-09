<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Response;

use Framework\Http\Response\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Response::class)]
final class ResponseHelpersTest extends TestCase
{
    public function testRedirectDefaultsToStatus302(): void
    {
        $response = Response::redirect('/login');

        self::assertSame(302, $response->status);
        self::assertSame('/login', $response->headers['Location']);
        self::assertSame('', $response->body);
    }

    public function testRedirectWithExplicitStatus301(): void
    {
        $response = Response::redirect('/login', 301);

        self::assertSame(301, $response->status);
        self::assertSame('/login', $response->headers['Location']);
    }

    public function testRedirectAcceptsAllSupportedRedirectCodes(): void
    {
        foreach ([301, 302, 303, 307, 308] as $status) {
            $response = Response::redirect('/x', $status);
            self::assertSame($status, $response->status, "status {$status}");
            self::assertSame('/x', $response->headers['Location']);
        }
    }

    public function testRedirectMergesAdditionalHeaders(): void
    {
        $response = Response::redirect('/login', 302, [
            'X-Trace-Id' => 'abc-123',
            'Cache-Control' => 'no-store',
        ]);

        self::assertSame('/login', $response->headers['Location']);
        self::assertSame('abc-123', $response->headers['X-Trace-Id']);
        self::assertSame('no-store', $response->headers['Cache-Control']);
    }

    public function testRedirectAdditionalHeadersCanOverrideLocation(): void
    {
        $response = Response::redirect('/login', 302, [
            'Location' => '/override',
        ]);

        self::assertSame('/override', $response->headers['Location']);
    }

    public function testRedirectRejectsNon3xxStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/3xx redirect code/');

        Response::redirect('/login', 200);
    }

    public function testRedirectRejects300(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Response::redirect('/login', 300);
    }

    public function testRedirectRejects304(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Response::redirect('/login', 304);
    }

    public function testRedirectRejects400(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Response::redirect('/login', 400);
    }

    public function testRedirectProducesEmptyBody(): void
    {
        $response = Response::redirect('/login', 302);

        self::assertSame('', $response->body);
    }

    public function testRedirectEmitsLocationHeaderLine(): void
    {
        $response = Response::redirect('/login', 302);

        self::assertContains('Location: /login', $response->toHeaderLines());
    }

    public function testNoContentReturns204WithEmptyBody(): void
    {
        $response = Response::noContent();

        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
        self::assertSame([], $response->headers);
    }

    public function testNoContentIsEquivalentToEmpty204(): void
    {
        self::assertEquals(Response::empty(204), Response::noContent());
    }
}
