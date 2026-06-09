<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Container;

use Framework\Container\Container;
use Framework\Container\ContainerException;
use Framework\Container\ContainerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(Container::class)]
final class ContainerCycleGuardTest extends TestCase
{
    public function testDirectSelfRecursionInFactoryThrows(): void
    {
        $container = new Container();
        $container->set('A', static fn(ContainerInterface $c) => $c->get('A'));

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency detected');

        $container->get('A');
    }

    public function testTwoStepCycleThrowsWithBothIdsInMessage(): void
    {
        $container = new Container();
        $container->set('A', static fn(ContainerInterface $c) => $c->get('B'));
        $container->set('B', static fn(ContainerInterface $c) => $c->get('A'));

        try {
            $container->get('A');
            self::fail('Expected ContainerException for circular dependency');
        } catch (ContainerException $e) {
            $message = $e->getMessage();
            self::assertStringContainsString('A', $message);
            self::assertStringContainsString('B', $message);
            self::assertStringContainsString('Circular dependency', $message);
        }
    }

    public function testThreeLevelCycleIncludesAllParticipantsInMessage(): void
    {
        $container = new Container();
        $container->set('A', static fn(ContainerInterface $c) => $c->get('B'));
        $container->set('B', static fn(ContainerInterface $c) => $c->get('C'));
        $container->set('C', static fn(ContainerInterface $c) => $c->get('A'));

        try {
            $container->get('A');
            self::fail('Expected ContainerException for circular dependency');
        } catch (ContainerException $e) {
            $message = $e->getMessage();
            self::assertStringContainsString('A', $message);
            self::assertStringContainsString('B', $message);
            self::assertStringContainsString('C', $message);
        }
    }

    public function testCycleAcrossAutowireAndFactoryThrows(): void
    {
        $container = new Container();
        $container->set(
            CycleLeaf::class,
            static fn(ContainerInterface $c) => $c->get(CycleRoot::class),
        );

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency');

        $container->get(CycleRoot::class);
    }

    public function testDeepFactoryChainDoesNotTriggerFalseCycle(): void
    {
        $container = new Container();

        $container->set('level.1', static fn(): stdClass => new stdClass());
        $container->set('level.2', static fn(ContainerInterface $c) => $c->get('level.1'));
        $container->set('level.3', static fn(ContainerInterface $c) => $c->get('level.2'));
        $container->set('level.4', static fn(ContainerInterface $c) => $c->get('level.3'));

        $fourth = $container->get('level.4');
        $first = $container->get('level.1');

        self::assertInstanceOf(stdClass::class, $fourth);
        self::assertSame($first, $fourth, 'All levels should resolve to the same singleton');
    }

    public function testResolutionSucceedsAfterFailedCycleDoesNotCorruptState(): void
    {
        $container = new Container();
        $container->set('cyclic', static fn(ContainerInterface $c) => $c->get('A'));
        $container->set('A', static fn(ContainerInterface $c) => $c->get('B'));
        $container->set('B', static fn(ContainerInterface $c) => $c->get('A'));

        try {
            $container->get('A');
            self::fail('Expected circular dependency exception');
        } catch (ContainerException) {
        }

        $container->set('clean', static fn(): stdClass => new stdClass());
        self::assertInstanceOf(stdClass::class, $container->get('clean'));
    }

    public function testResolvingMapIsClearedAfterSuccessfulResolution(): void
    {
        $container = new Container();
        $container->set('once', static fn(): stdClass => new stdClass());

        $container->get('once');
        $container->get('once');

        $reflection = new \ReflectionClass($container);
        $resolving = $reflection->getProperty('resolving');
        self::assertSame([], $resolving->getValue($container));
    }
}

final class CycleRoot
{
    public function __construct(
        public CycleLeaf $leaf,
    ) {
    }
}

final class CycleLeaf
{
}
