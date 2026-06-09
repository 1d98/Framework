<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Router;

use Framework\Http\Router\Route;
use Framework\Http\Router\RouteMemo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(Route::class)]
#[CoversClass(RouteMemo::class)]
final class RouteWithPrefixTest extends TestCase
{
    public function testWithPrefixReturnsNewInstance(): void
    {
        $route = new Route('GET', '/users', self::okHandler());

        $prefixed = $route->withPrefix('/api');

        self::assertNotSame($route, $prefixed);
        self::assertSame('/api/users', $prefixed->path);
        self::assertSame('/users', $route->path, 'original path is untouched');
    }

    public function testWithPrefixCarriesMethodHandlerAndConstraints(): void
    {
        $base = new Route(
            'POST',
            '/items/{id}',
            self::okHandler(),
            ['id' => '\d+'],
        );

        $prefixed = $base->withPrefix('/api/v2');

        self::assertSame('POST', $prefixed->method);
        self::assertSame($base->handler(), $prefixed->handler());
        self::assertNotNull($prefixed->matches('POST', '/api/v2/items/7'));
        self::assertNull(
            $prefixed->matches('POST', '/api/v2/items/abc'),
            'constraint map must thread through withPrefix()',
        );
    }

    public function testWithPrefixPreservesRegistrationOrder(): void
    {
        $base = new Route('GET', '/x', self::okHandler());
        $stamped = $base->withRegistrationOrder(4);

        $prefixed = $stamped->withPrefix('/api');

        self::assertSame(4, $prefixed->registrationOrder());
        self::assertSame(0, $base->registrationOrder(), 'original order is untouched');
    }

    public function testWithPrefixRebuildsCompiledPatternForNewPath(): void
    {
        $base = new Route('GET', '/users/{id}', self::okHandler());
        $base->matches('GET', '/users/1');
        $basePattern = self::readMemoField($base, 'compiledPattern');

        $prefixed = $base->withPrefix('/api');

        self::assertIsString($basePattern);
        self::assertSame('#^/users/(?P<id>[^/]+)$#', $basePattern);
        $prefixedPattern = self::readMemoField($prefixed, 'compiledPattern');
        self::assertIsString($prefixedPattern);
        self::assertSame('#^/api/users/(?P<id>[^/]+)$#', $prefixedPattern);
        self::assertNotSame($basePattern, $prefixedPattern);
    }

    public function testWithPrefixMatchesPrefixedPath(): void
    {
        $base = new Route('GET', '/users/{id}', self::okHandler());

        $prefixed = $base->withPrefix('/api');

        self::assertNotNull($prefixed->matches('GET', '/api/users/7'));
        self::assertNull($prefixed->matches('GET', '/users/7'), 'unprefixed path no longer matches');
    }

    public function testWithPrefixResetsSpecificityCacheAndRecomputes(): void
    {
        $base = new Route('GET', '/users/{id}', self::okHandler());
        $base->specificity();
        $baseScores = self::readMemoField($base, 'specificityCache');
        self::assertSame([2, 1], $baseScores, 'precondition: parent scored literal + param');

        $prefixed = $base->withPrefix('/api');

        self::assertNull(
            self::readMemoField($prefixed, 'specificityCache'),
            'new instance starts with a fresh specificity cache (path-dependent field invalidated)',
        );

        $prefixedScores = $prefixed->specificity();
        self::assertSame([2, 2, 1], $prefixedScores, 'specificity is recomputed against the prefixed path');
        self::assertSame(
            [2, 2, 1],
            self::readMemoField($prefixed, 'specificityCache'),
            'recomputed value is cached on the new memo, not the parent',
        );

        self::assertSame(
            [2, 1],
            self::readMemoField($base, 'specificityCache'),
            'parent memo is untouched by the child recomputation',
        );
    }

    public function testWithPrefixResetsStaticCacheAndRecomputes(): void
    {
        $staticBase = new Route('GET', '/users', self::okHandler());
        $staticBase->getNormalizedMethod();
        $staticBase->isStatic();
        self::assertTrue(
            self::readMemoField($staticBase, 'staticCache'),
            'precondition: static parent reports static (cache populated)',
        );

        $stillStatic = $staticBase->withPrefix('/api');

        self::assertNull(
            self::readMemoField($stillStatic, 'staticCache'),
            'static cache on the clone starts as null even though the parent had it cached as true',
        );
        self::assertTrue(
            $stillStatic->isStatic(),
            'static cache is recomputed: prefixed path is fully literal',
        );
        self::assertTrue(
            self::readMemoField($stillStatic, 'staticCache'),
            'recomputed static value lands on the new memo',
        );
    }

    public function testWithPrefixResetsStaticCacheWhenBecomingDynamic(): void
    {
        $staticBase = new Route('GET', '/users', self::okHandler());
        $staticBase->getNormalizedMethod();
        $staticBase->isStatic();
        self::assertTrue(
            self::readMemoField($staticBase, 'staticCache'),
            'precondition: static parent reports static',
        );

        $dynamicClone = $staticBase->withPrefix('/users/{id}');

        self::assertNull(
            self::readMemoField($dynamicClone, 'staticCache'),
            'static cache is null on the new instance even though the parent had it cached as true',
        );
        self::assertFalse(
            $dynamicClone->isStatic(),
            'static cache is recomputed: prefixed path now has a parameter',
        );
    }

    public function testWithPrefixRecomputesNormalizedMethod(): void
    {
        $base = new Route('get', '/x', self::okHandler());
        $base->getNormalizedMethod();
        self::assertSame('GET', self::readMemoField($base, 'normalizedMethod'));

        $prefixed = $base->withPrefix('/api');

        self::assertNull(
            self::readMemoField($prefixed, 'normalizedMethod'),
            'normalizedMethod is a method-derived cache; the new instance must rebuild it',
        );
        self::assertSame('GET', $prefixed->getNormalizedMethod());
    }

    public function testWithPrefixMemoIsIndependentOfParentMemo(): void
    {
        $base = new Route('GET', '/users/{id}', self::okHandler());
        $base->matches('GET', '/users/1');
        $base->specificity();
        $baseMemo = self::readMemo($base);

        $prefixed = $base->withPrefix('/api');
        $prefixed->matches('GET', '/api/users/2');
        $prefixed->specificity();
        $prefixedMemo = self::readMemo($prefixed);

        self::assertNotSame(
            $baseMemo,
            $prefixedMemo,
            'the new instance must carry a distinct memo object — no shared mutable state',
        );

        self::assertSame(
            '#^/users/(?P<id>[^/]+)$#',
            self::readMemoField($base, 'compiledPattern'),
            'parent compiledPattern is the unprefixed version',
        );
        self::assertSame(
            '#^/api/users/(?P<id>[^/]+)$#',
            self::readMemoField($prefixed, 'compiledPattern'),
            'child compiledPattern is the prefixed version',
        );

        self::assertSame(
            [2, 1],
            self::readMemoField($base, 'specificityCache'),
            'parent specificity is the unprefixed tuple',
        );
        self::assertSame(
            [2, 2, 1],
            self::readMemoField($prefixed, 'specificityCache'),
            'child specificity is the prefixed tuple — independent of the parent',
        );

        $base->specificity();
        self::assertSame(
            [2, 1],
            self::readMemoField($base, 'specificityCache'),
            'parent memo is untouched by the child recomputation',
        );
    }

    public function testWithPrefixOnRouteWithEmptyPrefixIsStillACloneWithFreshMemo(): void
    {
        $base = new Route('GET', '/users', self::okHandler());
        $base->matches('GET', '/users');

        $clone = $base->withPrefix('');

        self::assertNotSame($base, $clone);
        self::assertSame('/users', $clone->path);
        self::assertNotSame(
            self::readMemo($base),
            self::readMemo($clone),
            'even an empty prefix must produce a fresh memo — caller cannot rely on identity sharing',
        );
    }

    public function testWithPrefixRepeatedlyChainsFreshMemos(): void
    {
        $base = new Route('GET', '/x', self::okHandler());
        $base->getNormalizedMethod();

        $a = $base->withPrefix('/api');
        $b = $a->withPrefix('/v1');
        $c = $b->withPrefix('/admin');

        self::assertSame('/api/x', $a->path);
        self::assertSame('/v1/api/x', $b->path);
        self::assertSame('/admin/v1/api/x', $c->path);

        $memos = [
            self::readMemo($base),
            self::readMemo($a),
            self::readMemo($b),
            self::readMemo($c),
        ];
        $unique = array_unique(array_map('spl_object_id', $memos));
        self::assertCount(4, $unique, 'every prefix step produces an independent memo');
    }

    private static function okHandler(): \Closure
    {
        return static fn(): \Framework\Http\Response\Response => \Framework\Http\Response\Response::text('ok');
    }

    private static function readMemoField(Route $route, string $field): mixed
    {
        $memo = self::readMemo($route);
        return (new ReflectionClass($memo))->getProperty($field)->getValue($memo);
    }

    private static function readMemo(Route $route): RouteMemo
    {
        $memoProp = (new ReflectionClass($route))->getProperty('memo');
        $memo = $memoProp->getValue($route);
        self::assertInstanceOf(RouteMemo::class, $memo);
        return $memo;
    }
}
