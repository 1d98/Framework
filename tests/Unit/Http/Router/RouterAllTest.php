<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Router;

use Framework\Http\Router\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
final class RouterAllTest extends TestCase
{
    public function testAllReturnsEmptyArrayWhenNoRoutes(): void
    {
        $router = new Router();
        self::assertSame([], $router->all());
    }

    public function testAllReturnsMethodAndPathForEachRoute(): void
    {
        $router = new Router();
        $router->get('/users', static fn() => new \Framework\Http\Response\Response());
        $router->post('/users', static fn() => new \Framework\Http\Response\Response());
        $router->delete('/users/{id}', static fn() => new \Framework\Http\Response\Response());

        $all = $router->all();
        self::assertCount(3, $all);
        self::assertContains(['method' => 'GET', 'path' => '/users'], $all);
        self::assertContains(['method' => 'POST', 'path' => '/users'], $all);
        self::assertContains(['method' => 'DELETE', 'path' => '/users/{id}'], $all);
    }

    public function testAllReturnsListShape(): void
    {
        $router = new Router();
        $router->get('/a', static fn() => new \Framework\Http\Response\Response());
        $all = $router->all();
        self::assertNotEmpty($all);
        self::assertArrayHasKey(0, $all);
        self::assertSame(['method', 'path'], array_keys($all[0]));
    }

    public function testAllAfterGroupExpansionIncludesGroupRoutes(): void
    {
        $router = new Router();
        $router->group('/api/v1', static function (Router $r): void {
            $r->get('/users', static fn() => new \Framework\Http\Response\Response());
            $r->get('/orders', static fn() => new \Framework\Http\Response\Response());
        });

        $all = $router->all();
        self::assertCount(2, $all);
        $paths = array_map(static fn(array $r): string => $r['path'], $all);
        self::assertContains('/api/v1/users', $paths);
        self::assertContains('/api/v1/orders', $paths);
    }

    public function testAllDoesNotExposeHandler(): void
    {
        $router = new Router();
        $router->get('/x', static fn() => new \Framework\Http\Response\Response());
        $all = $router->all();
        self::assertArrayNotHasKey('handler', $all[0]);
    }
}
