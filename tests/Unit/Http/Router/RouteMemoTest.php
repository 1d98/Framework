<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Router;

use Framework\Http\Router\RouteMemo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(RouteMemo::class)]
final class RouteMemoTest extends TestCase
{
    public function testDefaultsAreNullOrZero(): void
    {
        $memo = new RouteMemo();

        self::assertNull($memo->compiledPattern);
        self::assertNull($memo->normalizedMethod);
        self::assertNull($memo->staticCache);
        self::assertNull($memo->specificityCache);
        self::assertSame(0, $memo->registrationOrder);
    }

    public function testMutableFieldsCanBeAssignedInPlace(): void
    {
        $memo = new RouteMemo();

        $memo->compiledPattern = '#^/x$#';
        $memo->normalizedMethod = 'GET';
        $memo->staticCache = true;
        $memo->specificityCache = [1, 2, 3];
        $memo->registrationOrder = 5;

        self::assertSame('#^/x$#', $memo->compiledPattern);
        self::assertSame('GET', $memo->normalizedMethod);
        self::assertTrue($memo->staticCache);
        self::assertSame([1, 2, 3], $memo->specificityCache);
        self::assertSame(5, $memo->registrationOrder);
    }

    public function testConstructorAcceptsPrefilledState(): void
    {
        $memo = new RouteMemo(
            compiledPattern: '#^/users$#',
            normalizedMethod: 'POST',
            staticCache: false,
            specificityCache: [2, 1],
            registrationOrder: 9,
        );

        self::assertSame('#^/users$#', $memo->compiledPattern);
        self::assertSame('POST', $memo->normalizedMethod);
        self::assertFalse($memo->staticCache);
        self::assertSame([2, 1], $memo->specificityCache);
        self::assertSame(9, $memo->registrationOrder);
    }

    public function testClassIsFinalAndNotReadonly(): void
    {
        $reflection = new ReflectionClass(RouteMemo::class);

        self::assertTrue($reflection->isFinal(), 'RouteMemo must be final');
        self::assertFalse(
            $reflection->isReadOnly(),
            'RouteMemo is intentionally non-readonly so its lazy fields can be filled in '
            . 'place by Route without re-allocating the route on the hot path. The '
            . 'state lives off-`Route` (which IS readonly) precisely so the memo can be '
            . 'mutable.',
        );
    }
}
