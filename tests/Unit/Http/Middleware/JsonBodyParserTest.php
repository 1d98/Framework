<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Middleware;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Middleware\JsonBodyParser;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonBodyParser::class)]
final class JsonBodyParserTest extends TestCase
{
    public function testParsesApplicationJsonBody(): void
    {
        $request = new Request('POST', '/api/echo', '', ['content-type' => 'application/json'], '{"name":"Alice","age":30}');
        $middleware = new JsonBodyParser();
        $captured = null;

        $middleware->process($request, static function (Request $r) use (&$captured): Response {
            $captured = $r;
            return Response::json(['ok' => true]);
        });

        self::assertInstanceOf(Request::class, $captured);
        self::assertSame(['name' => 'Alice', 'age' => 30], $captured->json());
    }

    public function testParsesApplicationJsonWithCharset(): void
    {
        $request = new Request('POST', '/x', '', ['content-type' => 'application/json; charset=utf-8'], '[1,2,3]');
        $middleware = new JsonBodyParser();
        $captured = null;

        $middleware->process($request, static function (Request $r) use (&$captured): Response {
            $captured = $r;
            return Response::json([]);
        });

        self::assertInstanceOf(Request::class, $captured);
        self::assertSame([1, 2, 3], $captured->json());
    }

    public function testSkipsNonJsonContentType(): void
    {
        $request = new Request('POST', '/x', '', ['content-type' => 'application/x-www-form-urlencoded'], 'a=1&b=2');
        $middleware = new JsonBodyParser();
        $captured = null;

        $middleware->process($request, static function (Request $r) use (&$captured): Response {
            $captured = $r;
            return Response::text('ok');
        });

        self::assertInstanceOf(Request::class, $captured);
        self::assertNull($captured->json(), 'Non-JSON Content-Type should leave json as null');
    }

    public function testSkipsEmptyJsonBody(): void
    {
        $request = new Request('POST', '/x', '', ['content-type' => 'application/json'], '');
        $middleware = new JsonBodyParser();
        $captured = null;

        $middleware->process($request, static function (Request $r) use (&$captured): Response {
            $captured = $r;
            return Response::text('ok');
        });

        self::assertInstanceOf(Request::class, $captured);
        self::assertNull($captured->json());
    }

    public function testThrowsOnInvalidJson(): void
    {
        $request = new Request('POST', '/x', '', ['content-type' => 'application/json'], '{invalid json');
        $middleware = new JsonBodyParser();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid JSON:');

        $middleware->process($request, static fn(Request $r): Response => Response::text('never'));
    }

    public function testHandlesJsonNullLiteral(): void
    {
        $request = new Request('POST', '/x', '', ['content-type' => 'application/json'], 'null');
        $middleware = new JsonBodyParser();
        $captured = null;

        $middleware->process($request, static function (Request $r) use (&$captured): Response {
            $captured = $r;
            return Response::text('ok');
        });

        self::assertInstanceOf(Request::class, $captured);
        self::assertNull($captured->json());
    }
}
