<?php

declare(strict_types=1);

namespace Framework\Tests\Unit;

use Framework\Exception\ConfigException;
use Framework\Exception\FrameworkException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(FrameworkException::class)]
final class FrameworkExceptionTest extends TestCase
{
    public function testItIsARuntimeException(): void
    {
        $exception = new ConfigException('boom');

        self::assertInstanceOf(FrameworkException::class, $exception);
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertInstanceOf(Throwable::class, $exception);
        self::assertSame('boom', $exception->getMessage());
    }

    public function testItCarriesCodeAndPrevious(): void
    {
        $previous = new RuntimeException('cause');
        $exception = new ConfigException('outer', 42, $previous);

        self::assertSame(42, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}
