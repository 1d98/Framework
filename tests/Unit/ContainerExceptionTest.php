<?php

declare(strict_types=1);

namespace Framework\Tests\Unit;

use Framework\Container\ContainerException;
use Framework\Container\NotFoundException;
use Framework\Exception\FrameworkException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ContainerException::class)]
#[CoversClass(NotFoundException::class)]
final class ContainerExceptionTest extends TestCase
{
    public function testContainerExceptionExtendsFrameworkException(): void
    {
        $exception = new ContainerException('oops');

        self::assertInstanceOf(FrameworkException::class, $exception);
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame('oops', $exception->getMessage());
    }

    public function testNotFoundExceptionExtendsContainerException(): void
    {
        $exception = new NotFoundException('missing');

        self::assertInstanceOf(ContainerException::class, $exception);
        self::assertInstanceOf(FrameworkException::class, $exception);
        self::assertSame('missing', $exception->getMessage());
    }
}
