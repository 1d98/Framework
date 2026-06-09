<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Router;

use Framework\Http\Response\Response;
use Framework\Http\Router\Route;
use Framework\Http\Router\RouteMemo;
use Framework\Http\Router\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(Router::class)]
#[CoversClass(Route::class)]
#[CoversClass(RouteMemo::class)]
final class RouterStaticMemoTest extends TestCase
{
    public function testThousandStaticRoutesAllocateZeroMemos(): void
    {
        $router = new Router();
        for ($i = 0; $i < 1000; $i++) {
            $router->get(sprintf('/static/%04d', $i), static fn(): Response => Response::text('ok'));
        }

        self::assertSame(
            0,
            $this->countMemosIn($router),
            '1000 static routes registered via Router::get() must allocate 0 RouteMemo instances',
        );
    }

    public function testThousandDynamicRoutesAllocateThousandMemos(): void
    {
        $router = new Router();
        for ($i = 0; $i < 1000; $i++) {
            $router->get(sprintf('/dyn/%04d/{id}', $i), static fn(): Response => Response::text('ok'));
        }

        self::assertSame(
            1000,
            $this->countMemosIn($router),
            '1000 dynamic routes registered via Router::get() must each allocate a RouteMemo (regression)',
        );
    }

    public function testStaticRouteFirstMatchesLazilyAllocatesMemo(): void
    {
        $route = new Route(
            'GET',
            '/users',
            static fn(): Response => Response::text('ok'),
            memo: null,
        );

        self::assertNull(
            $route->getMemo(),
            'precondition: route was constructed with memo: null — no allocation has happened yet',
        );

        $result = $route->matches('GET', '/users');
        self::assertNotNull($result);

        $memo = $route->getMemo();
        self::assertInstanceOf(
            RouteMemo::class,
            $memo,
            'first matches() call on a static route must lazily allocate the memo',
        );
    }

    public function testStaticRouteFirstMatchRemainsO1(): void
    {
        $router = new Router();
        $router->get('/users', static fn(): Response => Response::text('ok'));

        $request = new \Framework\Http\Request\Request('GET', '/users');

        $start = hrtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $router->match($request);
        }
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        $stats = $router->stats();
        self::assertSame(
            0,
            $stats['regexCalls'],
            'static match must NOT touch the regex matcher — it goes through the staticIndex hash lookup',
        );
        self::assertSame(
            1000,
            $stats['staticHits'],
            'all 1000 matches must register as staticHits',
        );
        self::assertLessThan(
            50.0,
            $elapsedMs,
            sprintf('1000 static matches took %.2fms (expected < 50ms — O(1) per match)', $elapsedMs),
        );
    }

    public function testMemoAccessorReturnsSameLazyInstanceAcrossCalls(): void
    {
        $route = new Route(
            'GET',
            '/users',
            static fn(): Response => Response::text('ok'),
            memo: null,
        );

        self::assertNull(
            $route->getMemo(),
            'precondition: getMemo() must return null before any cacheable call',
        );

        $first = $route->memo();
        $second = $route->memo();
        $third = $route->memo();

        self::assertInstanceOf(RouteMemo::class, $first);
        self::assertSame(
            $first,
            $second,
            'memo() must hand back the same RouteMemo instance on every call after the first',
        );
        self::assertSame(
            $second,
            $third,
            'memo() must not re-allocate on subsequent calls',
        );
        self::assertSame(
            $first,
            $route->getMemo(),
            'getMemo() now sees the lazily-allocated memo',
        );
    }

    public function testRouteConstructedWithoutMemoIsStillFullyFunctional(): void
    {
        $route = new Route(
            'GET',
            '/users/{id}',
            static fn(): Response => Response::text('ok'),
            memo: null,
        );

        self::assertNull($route->getMemo());
        self::assertNotNull($route->matches('GET', '/users/7'));
        self::assertNotNull($route->matches('GET', '/users/8'));
        self::assertNull($route->matches('GET', '/posts'));
        self::assertNotNull($route->getMemo(), 'matches() must have allocated the memo');
    }

    public function testIsStaticOnNullMemoRouteDoesNotAllocate(): void
    {
        $route = new Route(
            'GET',
            '/users',
            static fn(): Response => Response::text('ok'),
            memo: null,
        );

        self::assertTrue($route->isStatic(), 'static path is reported as static');
        self::assertNull(
            $route->getMemo(),
            'isStatic() must not allocate the memo just to cache the answer — static routes go through the hash lookup anyway',
        );
    }

    public function testGroupedStaticRoutesAlsoAllocateZeroMemos(): void
    {
        $router = new Router();
        $router->group('/api', static function (Router $r): void {
            for ($i = 0; $i < 100; $i++) {
                $r->get(sprintf('/users/%03d', $i), static fn(): Response => Response::text('ok'));
            }
        });

        self::assertSame(
            0,
            $this->countMemosIn($router),
            'static routes registered via group() must also allocate 0 memos',
        );
    }

    public function testExternalAddWithStaticRoutePreservesNullMemo(): void
    {
        $router = new Router();
        $staticRoute = new Route(
            'GET',
            '/health',
            static fn(): Response => Response::text('ok'),
            memo: null,
        );

        $router->add($staticRoute);

        self::assertNull(
            $staticRoute->getMemo(),
            'add() of an externally-constructed static route with null memo must keep memo at null',
        );
        self::assertSame(
            0,
            $this->countMemosIn($router),
            'external add() of static route must not allocate a memo',
        );
    }

    public function testExternalAddWithDynamicRouteAllocatesMemo(): void
    {
        $router = new Router();
        $dynamicRoute = new Route(
            'GET',
            '/users/{id}',
            static fn(): Response => Response::text('ok'),
            memo: null,
        );

        $router->add($dynamicRoute);

        $routesProp = (new ReflectionClass($router))->getProperty('routes');
        /** @var list<Route> $stored */
        $stored = $routesProp->getValue($router);
        self::assertCount(1, $stored);
        self::assertInstanceOf(
            RouteMemo::class,
            $stored[0]->getMemo(),
            'add() of a dynamic route must allocate a memo on the stored route (via withRegistrationOrder)',
        );
    }

    /**
     * Walk both internal route buckets via reflection and count the
     * non-null memo references. Reflection is the test-side mirror of
     * the production invariant: a `null` memo means "no allocation
     * has happened", and a non-null memo means the route is paying
     * the cache price.
     */
    private function countMemosIn(Router $router): int
    {
        $ref = new ReflectionClass($router);
        $staticIndexProp = $ref->getProperty('staticIndex');
        $routesProp = $ref->getProperty('routes');
        $memoProp = (new ReflectionClass(Route::class))->getProperty('memo');

        $count = 0;
        /** @var array<string, Route> $static */
        $static = $staticIndexProp->getValue($router);
        foreach ($static as $route) {
            if ($memoProp->getValue($route) instanceof RouteMemo) {
                $count++;
            }
        }
        /** @var list<Route> $dynamic */
        $dynamic = $routesProp->getValue($router);
        foreach ($dynamic as $route) {
            if ($memoProp->getValue($route) instanceof RouteMemo) {
                $count++;
            }
        }
        return $count;
    }
}
