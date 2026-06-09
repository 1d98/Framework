<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Container;

use Framework\Container\Container;
use Framework\Container\NotFoundException;
use Framework\Logging\LoggerInterface;
use Framework\Logging\NullLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Container::class)]
final class ContainerInterfaceEnumTest extends TestCase
{
    public function testHasReturnsTrueForInterfaceIdWithoutBinding(): void
    {
        $container = new Container();

        self::assertTrue(
            $container->has(LoggerInterface::class),
            'has() must recognise interface ids, not just class ids',
        );
    }

    public function testHasReturnsTrueForEnumIdWithoutBinding(): void
    {
        $container = new Container();

        self::assertTrue(
            $container->has(TestBackedEnum::class),
            'has() must recognise enum ids, not just class ids',
        );
    }

    public function testGetInterfaceWithoutBindingThrowsClearError(): void
    {
        $container = new Container();

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage(LoggerInterface::class);

        $container->get(LoggerInterface::class);
    }

    public function testGetInterfaceWithoutBindingMentionsBindInErrorMessage(): void
    {
        $container = new Container();

        try {
            $container->get(LoggerInterface::class);
            self::fail('Expected NotFoundException for unbound interface');
        } catch (NotFoundException $e) {
            self::assertStringContainsString(
                'bind',
                $e->getMessage(),
                'error must point the developer at $container->bind()',
            );
        }
    }

    public function testGetEnumWithoutBindingThrowsClearError(): void
    {
        $container = new Container();

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage(TestBackedEnum::class);

        $container->get(TestBackedEnum::class);
    }

    public function testBindInterfaceToClassStillWorksRegression(): void
    {
        $container = new Container();
        $container->bind(LoggerInterface::class, NullLogger::class);

        $logger = $container->get(LoggerInterface::class);

        self::assertInstanceOf(LoggerInterface::class, $logger);
        self::assertInstanceOf(NullLogger::class, $logger);
    }

    public function testHasReturnsTrueForBindingToInterfaceConcrete(): void
    {
        $container = new Container();
        $container->bind(LoggerInterface::class, LoggerInterface::class);

        self::assertTrue(
            $container->has(LoggerInterface::class),
            'has() must accept bindings whose concrete is an interface — the resolve-time check is what enforces the actual instantiation',
        );
    }

    public function testBindEnumToFactoryHasAndResolves(): void
    {
        $container = new Container();
        $container->bind(TestBackedEnum::class, static fn(): TestBackedEnum => TestBackedEnum::Foo);

        self::assertTrue($container->has(TestBackedEnum::class));

        $resolved = $container->get(TestBackedEnum::class);

        self::assertSame(TestBackedEnum::Foo, $resolved);
    }

    public function testTypeExistsMemoizationPopulatesStaticCache(): void
    {
        $container = new Container();

        $container->has(LoggerInterface::class);

        $reflection = new \ReflectionClass(Container::class);
        $prop = $reflection->getProperty('typeExistsCache');
        /** @var array<string, bool> $cache */
        $cache = $prop->getValue();

        self::assertArrayHasKey(
            LoggerInterface::class,
            $cache,
            'first has() call must populate the memoization cache',
        );
        self::assertTrue($cache[LoggerInterface::class]);
    }

    public function testTypeExistsMemoizationIsSharedAcrossInstances(): void
    {
        $containerA = new Container();
        $containerB = new Container();

        $containerA->has(LoggerInterface::class);

        $reflection = new \ReflectionClass(Container::class);
        $prop = $reflection->getProperty('typeExistsCache');

        /** @var array<string, bool> $cache */
        $cache = $prop->getValue();

        self::assertArrayHasKey(
            LoggerInterface::class,
            $cache,
            'memoization is static so the second container instance also benefits',
        );

        $containerB->has(NullLogger::class);

        /** @var array<string, bool> $cache */
        $cache = $prop->getValue();

        self::assertArrayHasKey(NullLogger::class, $cache);
    }

    public function testClearCachesDropsTypeExistsMemoization(): void
    {
        $container = new Container();
        $container->has(LoggerInterface::class);

        Container::clearCaches();

        $reflection = new \ReflectionClass(Container::class);
        $prop = $reflection->getProperty('typeExistsCache');
        /** @var array<string, bool> $cache */
        $cache = $prop->getValue();

        self::assertSame(
            [],
            $cache,
            'clearCaches() must drop the process-wide typeExists memoization',
        );
    }

    public function testHasReturnsFalseForUnknownId(): void
    {
        $container = new Container();

        self::assertFalse($container->has('Definitely\\Does\\Not\\Exist\\Anywhere'));
    }
}

enum TestBackedEnum: string
{
    case Foo = 'foo';
    case Bar = 'bar';
}
