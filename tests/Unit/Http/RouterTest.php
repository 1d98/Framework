<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Route;
use Framework\Http\Router\RouteNotFoundException;
use Framework\Http\Router\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
#[CoversClass(RouteNotFoundException::class)]
final class RouterTest extends TestCase
{
    public function testGetRegistersRoute(): void
    {
        $router = new Router();
        $route = $router->get('/users', static fn(): Response => Response::text('users'));

        self::assertInstanceOf(Route::class, $route);
        self::assertSame('GET', $route->method);
        self::assertSame('/users', $route->path);
    }

    public function testAllMethodsRegisterRoutes(): void
    {
        $router = new Router();
        $router->get('/g', static fn(): Response => Response::text('g'));
        $router->post('/p', static fn(): Response => Response::text('p'));
        $router->put('/u', static fn(): Response => Response::text('u'));
        $router->delete('/d', static fn(): Response => Response::text('d'));
        $router->patch('/pa', static fn(): Response => Response::text('pa'));

        self::assertArrayHasKey('handler', $router->match(new Request('GET', '/g')));
        self::assertArrayHasKey('handler', $router->match(new Request('POST', '/p')));
        self::assertArrayHasKey('handler', $router->match(new Request('PUT', '/u')));
        self::assertArrayHasKey('handler', $router->match(new Request('DELETE', '/d')));
        self::assertArrayHasKey('handler', $router->match(new Request('PATCH', '/pa')));
    }

    public function testFirstMatchWinsWhenStrictRegistrationIsDisabled(): void
    {
        $router = (new Router())->withStrict(false);
        $first = static fn(): Response => Response::text('first');
        $second = static fn(): Response => Response::text('second');

        $router->get('/x', $first);
        $router->get('/x', $second);

        $result = $router->match(new Request('GET', '/x'));

        self::assertSame($first, $result['handler']);
    }

    public function testMatchThrowsForUnmatchedRequest(): void
    {
        $router = new Router();
        $router->get('/users', static fn(): Response => Response::text('users'));

        $this->expectException(RouteNotFoundException::class);
        $this->expectExceptionMessage('No route matches GET /posts');

        $router->match(new Request('GET', '/posts'));
    }

    public function testAddAllowsManualRouteRegistration(): void
    {
        $router = new Router();
        $router->add(new Route('OPTIONS', '/x', static fn(): Response => Response::text('options')));

        self::assertArrayHasKey('handler', $router->match(new Request('OPTIONS', '/x')));
    }

    public function testMatchReturnsParams(): void
    {
        $router = new Router();
        $router->get('/users/{id}', static fn(Request $r, array $p): Response => Response::text($p['id']));

        $result = $router->match(new Request('GET', '/users/42'));

        self::assertSame(['id' => '42'], $result['params']);
    }
}
