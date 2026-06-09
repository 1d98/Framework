<?php

declare(strict_types=1);

namespace Framework\Tests\Unit;

use Framework\Exception\ConfigException;
use Framework\Exception\FrameworkException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ConfigException::class)]
final class ConfigExceptionTest extends TestCase
{
    public function testExtendsFrameworkException(): void
    {
        $exception = new ConfigException('bad config');

        self::assertInstanceOf(FrameworkException::class, $exception);
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame('bad config', $exception->getMessage());
    }
}
