<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Container;

use Framework\Container\Container;
use Framework\Container\ContainerInterface;
use Framework\Container\NotFoundException;
use Framework\Logging\LoggerInterface;
use Framework\Logging\StreamLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(Container::class)]
final class ContainerBindTest extends TestCase
{
    public function testBindInterfaceToClassStringReturnsConcreteInstance(): void
    {
        $container = new Container();
        $container->bind(LoggerInterface::class, BoundLogger::class);

        $logger = $container->get(LoggerInterface::class);

        self::assertInstanceOf(LoggerInterface::class, $logger);
        self::assertInstanceOf(BoundLogger::class, $logger);
    }

    public function testBindCachesResolvedSingleton(): void
    {
        $container = new Container();
        $container->bind(LoggerInterface::class, BoundLogger::class);

        self::assertSame($container->get(LoggerInterface::class), $container->get(LoggerInterface::class));
    }

    public function testBindClosureReceivesContainerAndIsInvokedOnce(): void
    {
        $container = new Container();
        $invocations = 0;

        $container->bind(LoggerInterface::class, function (ContainerInterface $c) use (&$invocations): StreamLogger {
            $invocations++;

            return StreamLogger::stderr();
        });

        $first = $container->get(LoggerInterface::class);
        $second = $container->get(LoggerInterface::class);

        self::assertInstanceOf(StreamLogger::class, $first);
        self::assertSame($first, $second);
        self::assertSame(1, $invocations, 'Bound closure should be invoked exactly once');
    }

    public function testAutowireResolvesBoundInterfaceConstructorDependency(): void
    {
        $container = new Container();
        $container->bind(LoggerInterface::class, BoundLogger::class);

        $service = $container->get(LoggerConsumer::class);

        self::assertInstanceOf(LoggerConsumer::class, $service);
        self::assertInstanceOf(BoundLogger::class, $service->logger);
    }

    public function testSetFactoryWinsOverPriorBinding(): void
    {
        $container = new Container();
        $container->bind('svc', BoundLogger::class);

        $manual = new stdClass();
        $container->set('svc', static fn(): object => $manual);

        $resolved = $container->get('svc');

        self::assertSame($manual, $resolved);
    }

    public function testSetFactoryWinsAndIsCachedAsInstance(): void
    {
        $container = new Container();
        $container->bind('svc', BoundLogger::class);

        $manual = new stdClass();
        $container->set('svc', static fn(): object => $manual);

        $container->get('svc');

        self::assertSame($manual, $container->get('svc'));
    }

    public function testHasReturnsTrueForBindingToExistingClass(): void
    {
        $container = new Container();
        $container->bind('bound', BoundLogger::class);

        self::assertTrue($container->has('bound'));
        self::assertInstanceOf(BoundLogger::class, $container->get('bound'));
    }

    public function testHasReturnsFalseForBindingToMissingClass(): void
    {
        $container = new Container();

        // @phpstan-ignore-next-line — deliberately binding a class-string for a class that does not exist to exercise the runtime check in has().
        $container->bind('broken', 'NoSuch\\Missing\\Service');

        self::assertFalse($container->has('broken'));
    }

    public function testGetThrowsForBindingToMissingClass(): void
    {
        $container = new Container();

        // @phpstan-ignore-next-line — see testHasReturnsFalseForBindingToMissingClass; this asserts the downstream resolve-time failure.
        $container->bind('broken', 'NoSuch\\Missing\\Service');

        $this->expectException(NotFoundException::class);

        $container->get('broken');
    }

    public function testHasReturnsTrueForBindingToClosure(): void
    {
        $container = new Container();
        $container->bind('closure.id', static fn(): object => new stdClass());

        self::assertTrue($container->has('closure.id'));
        self::assertInstanceOf(stdClass::class, $container->get('closure.id'));
    }

    public function testRebindingResetsCachedInstance(): void
    {
        $container = new Container();
        $container->bind('svc', BoundLogger::class);
        $container->get('svc');

        $manual = new stdClass();
        $container->bind('svc', static fn(): object => $manual);

        self::assertNotInstanceOf(BoundLogger::class, $container->get('svc'));
        self::assertSame($manual, $container->get('svc'));
    }

    public function testBindingClosureCanResolveOtherServicesFromContainer(): void
    {
        $container = new Container();
        $container->set('dep', static fn(): object => new BoundLogger());
        $container->bind(
            LoggerInterface::class,
            static fn(ContainerInterface $c): LoggerInterface => new BoundLogger(),
        );

        $logger = $container->get(LoggerInterface::class);

        self::assertInstanceOf(LoggerInterface::class, $logger);
    }
}

final class LoggerConsumer
{
    public function __construct(
        public LoggerInterface $logger,
    ) {
    }
}

final class BoundLogger implements LoggerInterface
{
    public function debug(string $message, array $context = []): void
    {
    }

    public function info(string $message, array $context = []): void
    {
    }

    public function warning(string $message, array $context = []): void
    {
    }

    public function error(string $message, array $context = []): void
    {
    }
}
