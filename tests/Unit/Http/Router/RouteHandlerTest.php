<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Router;

use Closure;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Route;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TypeError;

#[CoversClass(Route::class)]
final class RouteHandlerTest extends TestCase
{
    public function testClosureHandlerIsStoredAsClosure(): void
    {
        $closure = static fn(Request $r, array $p): Response => Response::text('ok');

        $route = new Route('GET', '/x', $closure);

        self::assertInstanceOf(Closure::class, $route->handler());
    }

    public function testArrayHandlerWithInstanceAndMethodIsStoredAsClosure(): void
    {
        $controller = new RouteHandlerControllerFixture();
        $route = new Route('GET', '/x', [$controller, 'show']);

        self::assertInstanceOf(Closure::class, $route->handler());
        $response = ($route->handler())(self::makeRequest(), ['id' => '7']);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame('show:7', $response->body);
    }

    public function testFunctionNameHandlerIsStoredAsClosure(): void
    {
        $route = new Route('GET', '/x', 'strtoupper');

        self::assertInstanceOf(Closure::class, $route->handler());
    }

    public function testStaticMethodStringHandlerIsStoredAsClosure(): void
    {
        $route = new Route('GET', '/x', RouteHandlerStaticFixture::class . '::staticShow');

        self::assertInstanceOf(Closure::class, $route->handler());
        $response = ($route->handler())(self::makeRequest(), []);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame('static', $response->body);
    }

    public function testUnknownFunctionNameThrowsAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Route handler for GET /x is not callable');

        new Route('GET', '/x', 'this_function_does_not_exist_anywhere');
    }

    public function testUnknownClassInArrayHandlerThrowsAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Route handler for GET /x is not callable');

        new Route('GET', '/x', ['NoSuchClass_xyz123', 'method']);
    }

    public function testUnknownMethodOnExistingClassThrowsAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Route handler for GET /x is not callable');

        new Route('GET', '/x', [RouteHandlerStaticFixture::class, 'noSuchMethod']);
    }

    public function testNonStaticMethodOnClassStringThrowsAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Route handler for GET /x is not callable');

        new Route('GET', '/x', [RouteHandlerControllerFixture::class, 'show']);
    }

    public function testHandlerReturnsSameInstanceAcrossCalls(): void
    {
        $closure = static fn(): Response => Response::text('ok');
        $route = new Route('GET', '/x', $closure);

        self::assertSame($route->handler(), $route->handler());
    }

    public function testMatchesReturnsNormalizedClosure(): void
    {
        $controller = new RouteHandlerControllerFixture();
        $route = new Route('GET', '/items/{id}', [$controller, 'show']);

        $result = $route->matches('GET', '/items/42');
        self::assertNotNull($result);
        self::assertInstanceOf(Closure::class, $result['handler']);
        $response = ($result['handler'])(self::makeRequest(), ['id' => '42']);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame('show:42', $response->body);
    }

    public function testConstructorRejectsIntegerHandler(): void
    {
        $this->expectException(TypeError::class);

        /** @phpstan-ignore-next-line — intentional invalid type at runtime */
        new Route('GET', '/x', 42);
    }

    private static function makeRequest(): Request
    {
        return new Request('GET', '/x');
    }
}

final class RouteHandlerControllerFixture
{
    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        return Response::text('show:' . $params['id']);
    }
}

final class RouteHandlerStaticFixture
{
    /**
     * @param array<string, string> $params
     */
    public static function staticShow(Request $request, array $params): Response
    {
        return Response::text('static');
    }
}
