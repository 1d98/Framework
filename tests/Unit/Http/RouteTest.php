<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Http\Router\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(Route::class)]
final class RouteTest extends TestCase
{
    public function testClassIsFinalAndPublicSurfaceIsReadonly(): void
    {
        $reflection = new ReflectionClass(Route::class);
        self::assertTrue($reflection->isFinal(), 'Route must be final');
        self::assertFalse(
            $reflection->isReadOnly(),
            'Route is `final` (not `final readonly`) since R9: the `$memo` property is nullable '
            . 'and lazy-allocated for static routes (see {@see Route::memo()}), and a `readonly` '
            . 'class forbids mutating any property after construction. The public surface '
            . '(`$method`, `$path`, `$handler`, `$constraints`) stays `readonly` on the '
            . 'constructor; only `$memo` is mutable, and that is the explicit zero-alloc design.',
        );

        $methodProp = $reflection->getProperty('method');
        self::assertTrue($methodProp->isReadOnly(), 'public $method must remain readonly');
        $pathProp = $reflection->getProperty('path');
        self::assertTrue($pathProp->isReadOnly(), 'public $path must remain readonly');
    }

    public function testStaticPathMatches(): void
    {
        $route = new Route('GET', '/users', static fn(): \Framework\Http\Response\Response => \Framework\Http\Response\Response::text('ok'));

        $result = $route->matches('GET', '/users');

        self::assertNotNull($result);
        self::assertSame([], $result['params']);
    }

    public function testPathWithSingleParam(): void
    {
        $route = new Route('GET', '/users/{id}', static fn(): \Framework\Http\Response\Response => \Framework\Http\Response\Response::text('ok'));

        $result = $route->matches('GET', '/users/42');

        self::assertNotNull($result);
        self::assertSame(['id' => '42'], $result['params']);
    }

    public function testPathWithMultipleParams(): void
    {
        $route = new Route('GET', '/users/{userId}/posts/{postId}', static fn(): \Framework\Http\Response\Response => \Framework\Http\Response\Response::text('ok'));

        $result = $route->matches('GET', '/users/7/posts/123');

        self::assertNotNull($result);
        self::assertSame(['userId' => '7', 'postId' => '123'], $result['params']);
    }

    public function testMethodComparisonIsCaseInsensitive(): void
    {
        $route = new Route('get', '/x', static fn(): \Framework\Http\Response\Response => \Framework\Http\Response\Response::text('ok'));

        self::assertNotNull($route->matches('GET', '/x'));
        self::assertNotNull($route->matches('get', '/x'));
    }

    public function testWrongMethodReturnsNull(): void
    {
        $route = new Route('GET', '/users', static fn(): \Framework\Http\Response\Response => \Framework\Http\Response\Response::text('ok'));

        self::assertNull($route->matches('POST', '/users'));
    }

    public function testWrongPathReturnsNull(): void
    {
        $route = new Route('GET', '/users', static fn(): \Framework\Http\Response\Response => \Framework\Http\Response\Response::text('ok'));

        self::assertNull($route->matches('GET', '/posts'));
    }

    public function testParamDoesNotMatchAcrossSlashes(): void
    {
        $route = new Route('GET', '/users/{id}', static fn(): \Framework\Http\Response\Response => \Framework\Http\Response\Response::text('ok'));

        self::assertNull($route->matches('GET', '/users/42/extra'));
    }

    public function testTrailingSlashIsNotMatched(): void
    {
        $route = new Route('GET', '/users', static fn(): \Framework\Http\Response\Response => \Framework\Http\Response\Response::text('ok'));

        self::assertNull($route->matches('GET', '/users/'));
    }

    public function testHandlerIsCallable(): void
    {
        $called = false;
        $route = new Route('GET', '/x', static function () use (&$called): \Framework\Http\Response\Response {
            $called = true;
            return \Framework\Http\Response\Response::text('ok');
        });

        $result = $route->matches('GET', '/x');
        self::assertNotNull($result);
        ($result['handler'])();
        self::assertTrue($called);
    }

    public function testWhereReturnsNewRouteWithConstraintApplied(): void
    {
        $base = new Route('GET', '/users/{id}', static fn(): \Framework\Http\Response\Response => \Framework\Http\Response\Response::text('ok'));

        $constrained = $base->where('id', '\d+');

        self::assertNotSame($base, $constrained, 'where() must return a new instance');
        self::assertNotNull($constrained->matches('GET', '/users/42'));
        self::assertNull($constrained->matches('GET', '/users/abc'), 'constraint must reject non-digits');
    }

    public function testWhereLeavesOriginalRouteUntouched(): void
    {
        $base = new Route('GET', '/users/{id}', static fn(): \Framework\Http\Response\Response => \Framework\Http\Response\Response::text('ok'));

        $base->where('id', '\d+');

        self::assertNotNull($base->matches('GET', '/users/abc'), 'original route keeps default [^/]+ constraint');
    }

    public function testMemoIsThreadedIntoMatches(): void
    {
        $route = new Route('GET', '/users/{id}', static fn(): \Framework\Http\Response\Response => \Framework\Http\Response\Response::text('ok'));

        self::assertNull(
            $route->getMemo(),
            'memo starts as null after construction; it is allocated lazily on the first match',
        );

        $route->matches('GET', '/users/7');

        $pattern = self::readMemoField($route, 'compiledPattern');
        self::assertIsString($pattern);
        self::assertStringContainsString('(?P<id>', $pattern);
        self::assertSame('GET', self::readMemoField($route, 'normalizedMethod'));
    }

    public function testSecondMatchReusesMemoizedPattern(): void
    {
        $route = new Route('GET', '/a/{x}', static fn(): \Framework\Http\Response\Response => \Framework\Http\Response\Response::text('ok'));

        $route->matches('GET', '/a/1');
        $firstPattern = self::readMemoField($route, 'compiledPattern');

        $route->matches('GET', '/a/2');
        $secondPattern = self::readMemoField($route, 'compiledPattern');

        self::assertSame($firstPattern, $secondPattern, 'cached pattern must be returned on the second call');
    }

    public function testNoSetRegistrationOrderMethodExists(): void
    {
        $reflection = new ReflectionClass(Route::class);

        self::assertFalse(
            $reflection->hasMethod('setRegistrationOrder'),
            'Route is final readonly: registration order is stamped via withRegistrationOrder() '
            . 'which returns a new instance. A void-mutating setter would violate the '
            . 'immutability invariant and would be a contradiction on a readonly class.',
        );
    }

    public function testWithRegistrationOrderReturnsNewInstanceAndStampsIndex(): void
    {
        $route = new Route('GET', '/x', static fn(): \Framework\Http\Response\Response => \Framework\Http\Response\Response::text('ok'));

        $stamped = $route->withRegistrationOrder(7);

        self::assertNotSame($route, $stamped);
        self::assertSame(0, $route->registrationOrder());
        self::assertSame(7, $stamped->registrationOrder());
    }

    public function testWithRegistrationOrderPreservesLazyCaches(): void
    {
        $route = new Route('GET', '/x', static fn(): \Framework\Http\Response\Response => \Framework\Http\Response\Response::text('ok'));
        $route->matches('GET', '/x');
        $pattern = self::readMemoField($route, 'compiledPattern');

        $stamped = $route->withRegistrationOrder(3);

        self::assertSame($pattern, self::readMemoField($stamped, 'compiledPattern'), 'memo is preserved on withRegistrationOrder');
    }

    private static function readMemoField(Route $route, string $field): mixed
    {
        $memoProp = (new ReflectionClass($route))->getProperty('memo');
        /** @var object $memo */
        $memo = $memoProp->getValue($route);
        return (new ReflectionClass($memo))->getProperty($field)->getValue($memo);
    }
}
