<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Http\Request\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
final class RequestJsonTest extends TestCase
{
    public function testJsonIsNullByDefault(): void
    {
        $request = new Request('POST', '/api/users');

        self::assertNull($request->json());
    }

    public function testWithJsonReturnsNewInstance(): void
    {
        $original = new Request('POST', '/api/users');
        $modified = $original->withJson(['name' => 'Alice']);

        self::assertNotSame($original, $modified);
        self::assertNull($original->json());
        self::assertSame(['name' => 'Alice'], $modified->json());
    }

    public function testWithJsonPreservesOtherProperties(): void
    {
        $original = new Request('POST', '/api/users', 'a=1', ['x-foo' => 'bar'], '{"k":1}');
        $modified = $original->withJson(['parsed' => true]);

        self::assertSame('POST', $modified->method);
        self::assertSame('/api/users', $modified->path);
        self::assertSame('a=1', $modified->queryString);
        self::assertSame(['x-foo' => 'bar'], $modified->headers);
        self::assertSame('{"k":1}', $modified->body);
        self::assertSame(['parsed' => true], $modified->json());
    }

    public function testWithJsonAllowsNull(): void
    {
        $request = (new Request('POST', '/x'))->withJson(['k' => 'v']);
        $cleared = $request->withJson(null);

        self::assertNull($cleared->json());
    }

    public function testWithJsonAllowsNestedData(): void
    {
        $data = ['user' => ['name' => 'Alice', 'roles' => ['admin', 'editor']]];
        $request = (new Request('POST', '/x'))->withJson($data);

        self::assertSame($data, $request->json());
    }
}
