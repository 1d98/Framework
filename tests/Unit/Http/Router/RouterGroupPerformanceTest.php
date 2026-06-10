<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Router;

use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
final class RouterGroupPerformanceTest extends TestCase
{
    public function testHundredGroupedRoutesRegisterAndMatch(): void
    {
        $router = $this->buildGroupedRouter(groups: 10, perGroup: 10);

        self::assertCount(100, $router->getRoutes());

        for ($g = 0; $g < 10; $g++) {
            $path = sprintf('/api/v%d/users/%03d', $g + 1, $g);
            $result = $router->match(new Request('GET', $path));
            self::assertArrayHasKey('handler', $result, "missing handler for {$path}");
        }
    }

    public function testThousandGroupedRoutesRegistrationIsLinear(): void
    {
        $router = new Router();

        $start = hrtime(true);
        $router->group('/api', static function (Router $r): void {
            for ($i = 0; $i < 1000; $i++) {
                $r->get(sprintf('/items/%04d', $i), static fn(): Response => Response::text('ok'));
            }
        });
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        self::assertCount(1000, $router->getRoutes());
        self::assertLessThan(
            500.0,
            $elapsedMs,
            sprintf('group() of 1000 routes took %.2fms (expected < 500ms — linear, not quadratic)', $elapsedMs),
        );
    }

    public function testThousandGroupedRoutesMatchIsO1PerRoute(): void
    {
        $router = $this->buildGroupedRouter(groups: 10, perGroup: 100);

        $request = new Request('GET', '/api/v5/users/004');
        $router->match($request);

        $router->resetStats();
        $start = hrtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $router->match($request);
        }
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        $stats = $router->stats();
        self::assertSame(
            0,
            $stats['regexCalls'],
            'grouped static route must hit the static index — no regex on match()',
        );
        self::assertSame(
            1000,
            $stats['staticHits'],
            'all 1000 matches must resolve through the static index',
        );
        self::assertLessThan(
            50.0,
            $elapsedMs,
            sprintf('1000 cached matches took %.2fms (expected < 50ms)', $elapsedMs),
        );
    }

    public function testThousandGroupedDynamicRoutesMatchIsBounded(): void
    {
        $router = new Router();
        $router->group('/api', static function (Router $r): void {
            for ($i = 0; $i < 1000; $i++) {
                $r->get(sprintf('/items/%04d/{id}', $i), static fn(): Response => Response::text('ok'));
            }
        });

        $request = new Request('GET', '/api/items/0500/42');
        $router->match($request);

        $router->resetStats();
        $start = hrtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $router->match($request);
        }
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        $calls = $router->stats()['regexCalls'];
        self::assertSame(
            1000 * 501,
            $calls,
            'each match() must exit at the first hit (position 500 + 1 = 501 calls) and never re-iterate',
        );
        self::assertLessThan(
            500.0,
            $elapsedMs,
            sprintf('1000 dynamic matches took %.2fms (expected < 500ms — no per-call compile overhead)', $elapsedMs),
        );
    }

    public function testGroupedRouteCompiledPatternIsPreBuiltAtRegistration(): void
    {
        $router = new Router();
        $router->group('/api', static function (Router $r): void {
            $r->get('/users/{id}', static fn(): Response => Response::text('u'));
        });

        $routes = $router->getRoutes();
        self::assertCount(1, $routes);
        $route = $routes[0];

        $ref = new \ReflectionClass($route);
        $memoProp = $ref->getProperty('memo');
        /** @var object $memo */
        $memo = $memoProp->getValue($route);
        $compiled = (new \ReflectionClass($memo))->getProperty('compiledPattern')->getValue($memo);
        self::assertNotNull(
            $compiled,
            'group() must pre-compile the pattern so the first match() is O(1)',
        );
    }

    public function testGroupPreservesPrefixConcatenation(): void
    {
        $router = new Router();
        $router->group('/api', static function (Router $r): void {
            $r->get('/v1/users', static fn(): Response => Response::text('u'));
        });

        $result = $router->match(new Request('GET', '/api/v1/users'));
        self::assertArrayHasKey('handler', $result);
    }

    public function testNestedGroupsPreserveStack(): void
    {
        $router = new Router();
        $router->group('/api', static function (Router $outer): void {
            $outer->group('/v1', static function (Router $inner): void {
                $inner->get('/users', static fn(): Response => Response::text('u'));
                $inner->get('/orders', static fn(): Response => Response::text('o'));
            });
            $outer->get('/health', static fn(): Response => Response::text('h'));
        });

        self::assertArrayHasKey('handler', $router->match(new Request('GET', '/api/v1/users')));
        self::assertArrayHasKey('handler', $router->match(new Request('GET', '/api/v1/orders')));
        self::assertArrayHasKey('handler', $router->match(new Request('GET', '/api/health')));
    }

    public function testWhereClauseSurvivesGroupPrefix(): void
    {
        $router = new Router();
        $router->group('/api/v1', static function (Router $r): void {
            $base = $r->get('/users/{id}', static fn(Request $req, array $p): Response => Response::text($p['id']));
            $constrained = $base->where('id', '\d+');
            $r->add($constrained);
        });

        $result = $router->match(new Request('GET', '/api/v1/users/77'));
        self::assertSame(['id' => '77'], $result['params']);
    }

    public function testRoutesOutsideGroupAreNotPrefixed(): void
    {
        $router = new Router();
        $router->get('/health', static fn(): Response => Response::text('ok'));
        $router->group('/api', static function (Router $r): void {
            $r->get('/users', static fn(): Response => Response::text('u'));
        });

        $paths = array_map(static fn($entry): string => $entry['path'], $router->all());
        self::assertContains('/health', $paths);
        self::assertContains('/api/users', $paths);
        self::assertNotContains('/api/health', $paths);
    }

    public function testGroupPopOnExceptionKeepsRouterUsable(): void
    {
        $router = new Router();

        try {
            $router->group('/api', static function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('expected exception to propagate');
        } catch (\RuntimeException) {
        }

        $router->get('/health', static fn(): Response => Response::text('ok'));
        self::assertArrayHasKey('handler', $router->match(new Request('GET', '/health')));
    }

    public function testEmptyGroupRegistersNothing(): void
    {
        $router = new Router();
        $router->group('/api', static function (Router $r): void {
        });

        self::assertSame([], $router->getRoutes());
    }

    /**
     * @return Router
     */
    private function buildGroupedRouter(int $groups, int $perGroup): Router
    {
        $router = new Router();
        for ($g = 0; $g < $groups; $g++) {
            $router->group(
                sprintf('/api/v%d', $g + 1),
                static function (Router $r) use ($g, $perGroup): void {
                    for ($i = 0; $i < $perGroup; $i++) {
                        $r->get(
                            sprintf('/users/%03d', $i + $g),
                            static fn(): Response => Response::text('ok'),
                        );
                    }
                },
            );
        }
        return $router;
    }
}
