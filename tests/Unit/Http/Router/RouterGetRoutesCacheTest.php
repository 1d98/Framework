<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Router;

use Framework\Http\Response\Response;
use Framework\Http\Router\Route;
use Framework\Http\Router\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(Router::class)]
#[CoversClass(Route::class)]
final class RouterGetRoutesCacheTest extends TestCase
{
    public function testFirstCallComputesSecondCallReturnsSameArrayInstance(): void
    {
        $router = new Router();
        $router->get('/users', static fn(): Response => Response::text('list'));
        $router->post('/users', static fn(): Response => Response::text('create'));
        $router->delete('/users/{id}', static fn(): Response => Response::text('del'));

        $cached = self::readPrivate($router, 'cachedRoutes');
        self::assertNull($cached, 'cache must be empty before the first call');

        $first = $router->getRoutes();
        self::assertCount(3, $first);

        $cached = self::readPrivate($router, 'cachedRoutes');
        self::assertIsArray($cached, 'cache must be populated after the first call');
        self::assertSame($first, $cached, 'cache must hold the same array that was returned');

        $second = $router->getRoutes();
        self::assertSame($first, $second, 'second call must return the exact same array instance');
    }

    public function testCachedCallDoesNotRerunUsort(): void
    {
        $router = $this->buildRouter(50);

        $first = $router->getRoutes();
        $cached = self::readPrivate($router, 'cachedRoutes');
        self::assertIsArray($cached);

        $second = $router->getRoutes();

        self::assertSame(
            $first,
            $second,
            'cached call must hand back the same array — proving the comparator was not re-invoked',
        );
        self::assertSame(
            $cached,
            $second,
            'cached call must return the stored array, not a freshly sorted copy',
        );
    }

    public function testAddingARouteInvalidatesTheCache(): void
    {
        $router = new Router();
        $router->get('/a', static fn(): Response => Response::text('a'));

        $first = $router->getRoutes();
        self::assertCount(1, $first);
        $cachedKey = self::readPrivate($router, 'cachedKey');
        self::assertIsInt($cachedKey);
        self::assertSame(1, $cachedKey, 'version counter is bumped inside add()');

        $router->get('/b', static fn(): Response => Response::text('b'));

        $cachedKeyAfterAdd = self::readPrivate($router, 'cachedKey');
        self::assertSame(
            $cachedKey,
            $cachedKeyAfterAdd,
            'cachedKey is not eagerly cleared — the version counter change in add() is what invalidates',
        );

        $second = $router->getRoutes();
        self::assertCount(2, $second);
        self::assertNotSame(
            $first,
            $second,
            'after invalidation, getRoutes() must return a freshly computed array',
        );

        $cachedKeyRecomputed = self::readPrivate($router, 'cachedKey');
        self::assertIsInt($cachedKeyRecomputed);
        self::assertNotSame($cachedKey, $cachedKeyRecomputed);
        self::assertSame(2, $cachedKeyRecomputed);
    }

    public function testClonedRouterHasIndependentCacheAfterParallelAdds(): void
    {
        $parent = new Router(strict: true);
        $parent->get('/a', static fn(): Response => Response::text('a'));

        $clone = $parent->withStrict(false);

        $clone->get('/b', static fn(): Response => Response::text('b'));
        $parent->get('/c', static fn(): Response => Response::text('c'));

        $parentFirst = $parent->getRoutes();
        $cloneFirst = $clone->getRoutes();

        self::assertCount(2, $parentFirst);
        self::assertCount(2, $cloneFirst);

        $parentPaths = array_map(static fn(Route $r): string => $r->path, $parentFirst);
        $clonePaths = array_map(static fn(Route $r): string => $r->path, $cloneFirst);

        self::assertContains('/a', $parentPaths);
        self::assertContains('/c', $parentPaths);
        self::assertNotContains('/b', $parentPaths, 'parent must not see routes added to the clone');

        self::assertContains('/a', $clonePaths);
        self::assertContains('/b', $clonePaths);
        self::assertNotContains('/c', $clonePaths, 'clone must not see routes added to the parent');

        $parentSecond = $parent->getRoutes();
        $cloneSecond = $clone->getRoutes();

        self::assertSame(
            $parentFirst,
            $parentSecond,
            'second getRoutes() on parent must return the same array instance (no rebuild)',
        );
        self::assertSame(
            $cloneFirst,
            $cloneSecond,
            'second getRoutes() on clone must return the same array instance (no rebuild)',
        );
        self::assertNotSame(
            $parentFirst,
            $cloneFirst,
            'parent and clone must have independent cached arrays — they are separate routers',
        );
    }

    public function testCacheStaysValidWhenNoRouteIsAdded(): void
    {
        $router = $this->buildRouter(20);

        $router->getRoutes();
        $keyAfterFirst = self::readPrivate($router, 'cachedKey');
        self::assertIsInt($keyAfterFirst);

        for ($i = 0; $i < 5; $i++) {
            $router->getRoutes();
        }

        $keyAfterMany = self::readPrivate($router, 'cachedKey');
        self::assertSame(
            $keyAfterFirst,
            $keyAfterMany,
            'repeated getRoutes() calls without add() must reuse the same cache key',
        );
    }

    public function testThousandCallsOnHundredRoutesCompleteUnderBudget(): void
    {
        $router = $this->buildRouter(100);

        $warmupStart = hrtime(true);
        for ($i = 0; $i < 10; $i++) {
            $router->getRoutes();
        }
        $warmupElapsedNs = hrtime(true) - $warmupStart;

        $coldStart = hrtime(true);
        $router->getRoutes();
        $coldElapsedNs = hrtime(true) - $coldStart;

        $start = hrtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $router->getRoutes();
        }
        $elapsedNs = hrtime(true) - $start;
        $elapsedMs = $elapsedNs / 1_000_000;

        self::assertLessThan(
            50.0,
            $elapsedMs,
            sprintf(
                '1000 cached getRoutes() calls on 100 routes took %.2fms (expected < 50ms)',
                $elapsedMs,
            ),
        );

        $cacheHitsAreCheaper = ($elapsedNs / 1000.0) < max($coldElapsedNs, $warmupElapsedNs / 10.0);
        self::assertTrue(
            $cacheHitsAreCheaper,
            sprintf(
                'cached per-call cost (%.2fus) must be cheaper than the cold path (%.2fus)',
                $elapsedNs / 1000.0,
                $coldElapsedNs,
            ),
        );
    }

    public function testGroupExpansionPopulatesCacheAndStaysConsistent(): void
    {
        $router = new Router();
        $router->group('/api', static function (Router $r): void {
            $r->get('/users', static fn(): Response => Response::text('u'));
            $r->get('/orders', static fn(): Response => Response::text('o'));
        });

        $first = $router->getRoutes();
        self::assertCount(2, $first);

        $second = $router->getRoutes();
        self::assertSame(
            $first,
            $second,
            'group() must populate the cache, and subsequent calls must return the same instance',
        );
    }

    public function testEmptyRouterCachesEmptyList(): void
    {
        $router = new Router();

        $first = $router->getRoutes();
        self::assertSame([], $first);

        $second = $router->getRoutes();
        self::assertSame($first, $second, 'empty result must also be cached');
    }

    /**
     * @return Router
     */
    private function buildRouter(int $count): Router
    {
        $router = new Router();
        for ($i = 0; $i < $count; $i++) {
            $router->get(sprintf('/items/%03d', $i), static fn(): Response => Response::text('x'));
            if ($i % 5 === 0) {
                $router->get(sprintf('/items/%03d/{id}', $i), static fn(): Response => Response::text('d'));
            }
        }
        return $router;
    }

    /**
     * @return mixed
     */
    private static function readPrivate(object $instance, string $property): mixed
    {
        $ref = new ReflectionClass($instance);
        $prop = $ref->getProperty($property);
        return $prop->getValue($instance);
    }
}
