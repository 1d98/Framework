<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Http\Exception\InternalServerErrorHttpException;
use Framework\Http\Response\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Response::class)]
final class ResponseTest extends TestCase
{
    public function testDefaults(): void
    {
        $response = new Response();

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
        self::assertSame([], $response->headers);
    }

    public function testTextFactorySetsContentType(): void
    {
        $response = Response::text('hello');

        self::assertSame(200, $response->status);
        self::assertSame('hello', $response->body);
        self::assertSame('text/plain; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testHtmlFactorySetsContentType(): void
    {
        $response = Response::html('<h1>Hi</h1>');

        self::assertSame('text/html; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testJsonFactorySerializesData(): void
    {
        $response = Response::json(['key' => 'value', 'n' => 42]);

        self::assertSame(200, $response->status);
        self::assertSame('application/json', $response->headers['Content-Type']);
        self::assertJson($response->body);
        self::assertSame(['key' => 'value', 'n' => 42], json_decode($response->body, true));
    }

    public function testJsonFactoryAcceptsCustomStatus(): void
    {
        $response = Response::json(['error' => 'oops'], 422);

        self::assertSame(422, $response->status);
    }

    public function testJsonFactoryThrowsOnEncodeFailure(): void
    {
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);

        try {
            $this->expectException(InternalServerErrorHttpException::class);
            $this->expectExceptionMessageMatches('/json_encode/');
            Response::json(['bad' => $resource]);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    public function testJsonFactoryEncodeFailureHasStatusFiveHundred(): void
    {
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);

        try {
            try {
                Response::json(['bad' => $resource]);
                self::fail('Expected InternalServerErrorHttpException');
            } catch (InternalServerErrorHttpException $e) {
                self::assertSame(500, $e->statusCode);
                self::assertSame('Internal Server Error', $e->title);
            }
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    public function testEmptyFactoryHasNoBody(): void
    {
        $response = Response::empty();

        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
    }

    public function testWithHeaderReturnsNewInstance(): void
    {
        $original = Response::text('hi');
        $modified = $original->withHeader('X-Custom', 'value');

        self::assertNotSame($original, $modified);
        self::assertArrayNotHasKey('X-Custom', $original->headers);
        self::assertSame('value', $modified->headers['X-Custom']);
    }

    public function testWithRequestIdSetsXRequestIdHeader(): void
    {
        $response = Response::text('hi')->withRequestId('abc-123');

        self::assertSame('abc-123', $response->headers['X-Request-Id']);
    }

    public function testWithRequestIdReturnsNewInstance(): void
    {
        $original = Response::text('hi');
        $modified = $original->withRequestId('abc-123');

        self::assertNotSame($original, $modified);
        self::assertArrayNotHasKey('X-Request-Id', $original->headers);
    }

    public function testWithStatusReturnsNewInstance(): void
    {
        $original = Response::text('hi');
        $modified = $original->withStatus(201);

        self::assertSame(200, $original->status);
        self::assertSame(201, $modified->status);
        self::assertSame($original->body, $modified->body);
    }

    public function testWithBodyReturnsNewInstance(): void
    {
        $original = Response::text('hi');
        $modified = $original->withBody('hello');

        self::assertSame('hi', $original->body);
        self::assertSame('hello', $modified->body);
    }

    public function testCookiesDefaultToEmptyArray(): void
    {
        $response = new Response();

        self::assertSame([], $response->cookies());
    }

    public function testWithCookieAppendsToList(): void
    {
        $cookieA = new \Framework\Http\Cookie\Cookie(name: 'a', value: '1');
        $cookieB = new \Framework\Http\Cookie\Cookie(name: 'b', value: '2');

        $response = Response::text('hi')
            ->withCookie($cookieA)
            ->withCookie($cookieB);

        $cookies = $response->cookies();
        self::assertCount(2, $cookies);
        self::assertSame($cookieA, $cookies[0]);
        self::assertSame($cookieB, $cookies[1]);
    }

    public function testWithCookieReturnsNewInstance(): void
    {
        $original = Response::text('hi');
        $modified = $original->withCookie(new \Framework\Http\Cookie\Cookie(name: 'a', value: '1'));

        self::assertNotSame($original, $modified);
        self::assertCount(0, $original->cookies());
        self::assertCount(1, $modified->cookies());
    }

    public function testSendEmitsSetCookieForEachCookie(): void
    {
        $response = Response::text('hi')
            ->withCookie(new \Framework\Http\Cookie\Cookie(name: 'a', value: '1', sameSite: 'Lax'))
            ->withCookie(new \Framework\Http\Cookie\Cookie(name: 'b', value: '2', sameSite: 'Strict'));

        ob_start();
        $response->send();
        $output = ob_get_clean();

        self::assertSame('hi', $output);
    }
}
