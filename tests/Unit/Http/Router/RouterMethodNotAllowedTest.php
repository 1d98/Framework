<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Router;

use Framework\Http\Exception\MethodNotAllowedHttpException;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Route;
use Framework\Http\Router\RouteMemo;
use Framework\Http\Router\RouteNotFoundException;
use Framework\Http\Router\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
#[CoversClass(MethodNotAllowedHttpException::class)]
#[CoversClass(Route::class)]
final class RouterMethodNotAllowedTest extends TestCase
{
    public function testPathWithDifferentMethodThrowsMethodNotAllowed(): void
    {
        $router = new Router();
        $router->get('/api/users', static fn(): Response => Response::text('list'));
        $router->post('/api/users', static fn(): Response => Response::text('create'));

        try {
            $router->match(new Request('OPTIONS', '/api/users'));
            self::fail('Expected MethodNotAllowedHttpException');
        } catch (MethodNotAllowedHttpException $e) {
            self::assertSame(405, $e->statusCode);
            self::assertSame(['GET', 'POST'], $e->allowedMethods());
            self::assertSame('GET, POST', $e->headers()['Allow'] ?? null);
        }
    }

    public function testUnknownPathStillThrowsRouteNotFound(): void
    {
        $router = new Router();
        $router->get('/api/users', static fn(): Response => Response::text('list'));

        $this->expectException(RouteNotFoundException::class);
        $this->expectExceptionMessage('No route matches GET /api/missing');

        $router->match(new Request('GET', '/api/missing'));
    }

    public function testAllowedMethodsAreDeduplicatedAndPreserveRegistrationOrder(): void
    {
        $router = (new Router())->withStrict(false);
        $router->get('/x', static fn(): Response => Response::text('g'));
        $router->post('/x', static fn(): Response => Response::text('p'));
        $router->get('/x', static fn(): Response => Response::text('g2'));
        $router->put('/x', static fn(): Response => Response::text('u'));

        try {
            $router->match(new Request('DELETE', '/x'));
            self::fail('Expected MethodNotAllowedHttpException');
        } catch (MethodNotAllowedHttpException $e) {
            self::assertSame(['GET', 'POST', 'PUT'], $e->allowedMethods());
        }
    }

    public function testPathWithParamYieldsAllowListAcrossMethods(): void
    {
        $router = new Router();
        $router->get('/users/{id}', static fn(): Response => Response::text('g'));
        $router->put('/users/{id}', static fn(): Response => Response::text('u'));

        try {
            $router->match(new Request('POST', '/users/42'));
            self::fail('Expected MethodNotAllowedHttpException');
        } catch (MethodNotAllowedHttpException $e) {
            self::assertSame(['GET', 'PUT'], $e->allowedMethods());
            self::assertSame('GET, PUT', $e->headers()['Allow'] ?? null);
        }
    }

    public function testMatchingMethodStillReturnsHandlerNotException(): void
    {
        $router = new Router();
        $handler = static fn(): Response => Response::text('ok');
        $router->get('/x', $handler);

        $result = $router->match(new Request('GET', '/x'));
        self::assertSame($handler, $result['handler']);
    }

    public function testEmptyRouterThrowsRouteNotFoundNotMethodNotAllowed(): void
    {
        $router = new Router();

        $this->expectException(RouteNotFoundException::class);

        $router->match(new Request('GET', '/anything'));
    }

    public function testNormalizedMethodIsCachedAfterFirstMatch(): void
    {
        $route = new Route(
            'get',
            '/x',
            static fn(): Response => Response::text('ok'),
        );

        $reflection = new \ReflectionClass(Route::class);
        $property = $reflection->getProperty('memo');

        self::assertNull(
            $route->getMemo(),
            'precondition: route was constructed without a memo — it is allocated lazily on the first match',
        );

        $route->matches('GET', '/x');
        self::assertSame('GET', self::readNormalizedMethod(self::readMemo($route, $property)));

        $route->matches('GET', '/x');
        $route->matches('GET', '/x');
        self::assertSame('GET', self::readNormalizedMethod(self::readMemo($route, $property)));
    }

    private static function readMemo(Route $route, \ReflectionProperty $property): RouteMemo
    {
        $value = $property->getValue($route);
        if (!$value instanceof RouteMemo) {
            self::fail('memo property is not a RouteMemo');
        }
        return $value;
    }

    private static function readNormalizedMethod(object $memo): ?string
    {
        $value = (new \ReflectionClass($memo))->getProperty('normalizedMethod')->getValue($memo);
        return is_string($value) ? $value : null;
    }

    public function testMethodComparisonStaysCaseInsensitiveAfterCaching(): void
    {
        $route = new Route(
            'GeT',
            '/users',
            static fn(): Response => Response::text('ok'),
        );

        self::assertNotNull($route->matches('GET', '/users'));
        self::assertNotNull($route->matches('get', '/users'));
        self::assertNotNull($route->matches('Get', '/users'));
        self::assertNull($route->matches('POST', '/users'));
    }
}
