<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Route;
use Framework\Http\Router\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
#[CoversClass(Route::class)]
final class RouterSpecificityTest extends TestCase
{
    public function testStaticRouteWinsOverParamRoute(): void
    {
        $router = new Router();
        $router->add(new Route('GET', '/users/{id}', static fn(Request $r, array $p): Response => Response::text('param')));
        $router->add(new Route('GET', '/users/me', static fn(Request $r, array $p): Response => Response::text('static')));

        self::assertSame('static', $this->dispatchBody($router, 'GET', '/users/me'));
    }

    public function testParamRouteWinsWhenStaticDoesNotMatch(): void
    {
        $router = new Router();
        $router->add(new Route('GET', '/users/{id}', static fn(Request $r, array $p): Response => Response::text('param')));
        $router->add(new Route('GET', '/users/me', static fn(Request $r, array $p): Response => Response::text('static')));

        self::assertSame('param', $this->dispatchBody($router, 'GET', '/users/42'));
    }

    public function testStaticFirstSegmentPrioritized(): void
    {
        $router = new Router();
        $router->add(new Route('GET', '/{section}/list', [$this, 'sectionHandler']));
        $router->add(new Route('GET', '/admin/list', static fn(Request $r, array $p): Response => Response::text('admin')));

        self::assertSame('admin', $this->dispatchBody($router, 'GET', '/admin/list'));
    }

    /**
     * @param array<string, string> $params
     */
    public function sectionHandler(Request $r, array $params): Response
    {
        return Response::text('param:' . $params['section']);
    }

    public function testRegistrationOrderPreservedForEqualSpecificity(): void
    {
        $router = new Router();
        $router->add(new Route('GET', '/a/{x}', static fn(): Response => Response::text('first')));
        $router->add(new Route('GET', '/b/{x}', static fn(): Response => Response::text('second')));

        self::assertSame('first', $this->dispatchBody($router, 'GET', '/a/v'));
    }

    public function testGetRoutesReturnsSortedBySpecificity(): void
    {
        $router = new Router();
        $router->add(new Route('GET', '/users/{id}', static fn(): Response => Response::text('p')));
        $router->add(new Route('GET', '/users/me', static fn(): Response => Response::text('s')));
        $router->add(new Route('GET', '/posts', static fn(): Response => Response::text('posts')));

        $paths = array_map(static fn(Route $r): string => $r->path, $router->getRoutes());

        // Per-segment lex compare: /users/me [2,2] > /users/{id} [2,1] > /posts [2].
        // The deeper tuple wins because all overlapping elements tie and length
        // is a tiebreaker for "more deeply specified".
        self::assertSame(['/users/me', '/users/{id}', '/posts'], $paths);
    }

    public function testRouteSpecificityScores(): void
    {
        self::assertSame([2, 2], (new Route('GET', '/users/me', static fn(): Response => Response::text('')))->specificity());
        self::assertSame([2, 1], (new Route('GET', '/users/{id}', static fn(): Response => Response::text('')))->specificity());
        self::assertSame([1, 1], (new Route('GET', '/{a}/{b}', static fn(): Response => Response::text('')))->specificity());
        self::assertSame([2, 1, 2], (new Route('GET', '/users/{id}/posts', static fn(): Response => Response::text('')))->specificity());
        self::assertSame([2, 2, 2], (new Route('GET', '/a/b/c', static fn(): Response => Response::text('')))->specificity());
    }

    public function testCompiledPatternIsCachedAcrossCalls(): void
    {
        $route = new Route('GET', '/users/{id}', static fn(): Response => Response::text(''));

        $r1 = $route->matches('GET', '/users/1');
        $r2 = $route->matches('GET', '/users/2');

        self::assertIsArray($r1);
        self::assertIsArray($r2);
        self::assertSame('1', $r1['params']['id']);
        self::assertSame('2', $r2['params']['id']);
    }

    public function testFullStaticPathBeatsAllParams(): void
    {
        $router = new Router();
        $router->add(new Route('GET', '/{a}/{b}/{c}', static fn(): Response => Response::text('all-params')));
        $router->add(new Route('GET', '/a/b/c', static fn(): Response => Response::text('all-static')));

        self::assertSame('all-static', $this->dispatchBody($router, 'GET', '/a/b/c'));
    }

    private function dispatchBody(Router $router, string $method, string $path): string
    {
        $result = $router->match(new Request($method, $path));
        $handler = $result['handler'];
        self::assertIsCallable($handler);
        $response = $handler(new Request($method, $path), $result['params']);
        self::assertInstanceOf(Response::class, $response);
        return $response->body;
    }
}
