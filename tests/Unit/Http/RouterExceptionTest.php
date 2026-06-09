<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Exception\FrameworkException;
use Framework\Http\Router\RouteNotFoundException;
use Framework\Http\Router\RouterException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RouterException::class)]
#[CoversClass(RouteNotFoundException::class)]
final class RouterExceptionTest extends TestCase
{
    public function testRouterExceptionExtendsFrameworkException(): void
    {
        $exception = new RouterException('router fail');

        self::assertInstanceOf(FrameworkException::class, $exception);
        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    public function testRouteNotFoundExceptionExtendsRouterException(): void
    {
        $exception = new RouteNotFoundException('no match');

        self::assertInstanceOf(RouterException::class, $exception);
        self::assertInstanceOf(FrameworkException::class, $exception);
    }
}
