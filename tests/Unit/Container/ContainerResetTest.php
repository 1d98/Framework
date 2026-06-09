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
final class ContainerResetTest extends TestCase
{
    public function testForgetForcesNextGetToReResolveFromFactory(): void
    {
        $container = new Container();
        $first = new stdClass();
        $second = new stdClass();

        $container->set('svc', static fn(): object => $first);
        self::assertSame($first, $container->get('svc'));
        self::assertSame(
            $first,
            $container->get('svc'),
            'precondition: factory result is cached as singleton',
        );

        $container->forget('svc');
        $container->set('svc', static fn(): object => $second);

        self::assertSame($second, $container->get('svc'));
    }

    public function testForgetOnUnknownIdIsNoOp(): void
    {
        $container = new Container();

        $container->forget('never.set');
        $container->forget('never.set');

        self::assertFalse($container->has('never.set'));
    }

    public function testForgetIsIdempotent(): void
    {
        $container = new Container();
        $container->set('svc', static fn(): object => new stdClass());
        $container->get('svc');

        $container->forget('svc');
        $container->forget('svc');

        self::assertTrue(
            $container->has('svc'),
            'factory registration survives forget(); only the cached instance is dropped',
        );
        self::assertInstanceOf(stdClass::class, $container->get('svc'));
    }


    public function testForgetDoesNotRemoveFactory(): void
    {
        $container = new Container();
        $container->set('svc', static fn(): object => new stdClass());

        $container->forget('svc');

        self::assertTrue($container->has('svc'));
        self::assertInstanceOf(stdClass::class, $container->get('svc'));
    }

    public function testResetClearsAllResolvedInstances(): void
    {
        $container = new Container();
        $container->set('a', static fn(): object => new stdClass());
        $container->set('b', static fn(): object => new stdClass());

        $container->get('a');
        $container->get('b');

        self::assertCount(2, $this->readInstances($container), 'precondition: both instances cached');

        $container->reset();

        self::assertSame([], $this->readInstances($container), 'reset() drops the entire singleton cache');
        self::assertTrue($container->has('a'), 'factory registration survives reset()');
        self::assertTrue($container->has('b'), 'factory registration survives reset()');
    }

    public function testResetPreservesBindingsAndFactories(): void
    {
        $container = new Container();
        $container->bind('bound', BoundResetTarget::class);
        $container->set('factory', static fn(): object => new BoundResetTarget());

        $container->get('bound');
        $container->get('factory');

        $container->reset();

        self::assertTrue($container->has('bound'));
        self::assertTrue($container->has('factory'));
        self::assertInstanceOf(BoundResetTarget::class, $container->get('bound'));
        self::assertInstanceOf(BoundResetTarget::class, $container->get('factory'));
    }

    public function testResetIsIdempotent(): void
    {
        $container = new Container();
        $container->set('svc', static fn(): object => new stdClass());
        $container->get('svc');

        $container->reset();
        $container->reset();
        $container->reset();

        self::assertSame([], $this->readInstances($container));
        self::assertTrue($container->has('svc'), 'factory registration is preserved across repeated reset()');
    }

    public function testResetOnEmptyContainerIsNoOp(): void
    {
        $container = new Container();

        $container->reset();

        self::assertSame([], $this->readInstances($container));
    }

    public function testResetKeepsLongRunningInstancesArrayBounded(): void
    {
        $container = new Container();
        $invocations = 0;
        $container->set(
            'transient',
            static function () use (&$invocations): object {
                $invocations++;

                return new stdClass();
            },
        );

        for ($i = 0; $i < 1000; $i++) {
            $container->get('transient');
        }

        self::assertCount(1, $this->readInstances($container), 'singleton is cached once across 1000 gets');
        self::assertSame(1, $invocations, 'factory itself runs only once while the singleton is cached');

        $container->reset();

        self::assertSame([], $this->readInstances($container));

        $container->get('transient');

        self::assertCount(1, $this->readInstances($container));
        self::assertSame(2, $invocations, 'factory re-runs after reset() to re-build the singleton');
    }

    public function testResetClearsResolvingState(): void
    {
        $container = new Container();
        $container->set('A', static fn(ContainerInterface $c) => $c->get('B'));
        $container->set('B', static fn(ContainerInterface $c) => $c->get('A'));

        try {
            $container->get('A');
            self::fail('Expected ContainerException for circular dependency');
        } catch (ContainerException) {
        }

        self::assertSame(
            [],
            $this->readResolving($container),
            'cycle guard clears itself on the failure path, so the map should be empty before reset()',
        );

        $container->reset();

        self::assertSame([], $this->readResolving($container));
    }

    public function testSetAndBindStillWorkAfterReset(): void
    {
        $container = new Container();
        $instance = new stdClass();
        $container->set('svc', static fn(): object => $instance);
        $container->get('svc');

        $container->reset();

        $container->set('svc', static fn(): object => $instance);
        self::assertTrue($container->has('svc'));
        self::assertSame($instance, $container->get('svc'));

        $container->reset();
        $container->bind('bound', BoundResetTarget::class);
        self::assertTrue($container->has('bound'));
        self::assertInstanceOf(BoundResetTarget::class, $container->get('bound'));
    }

    /**
     * @return array<string, object>
     */
    private function readInstances(Container $container): array
    {
        $reflection = new \ReflectionClass($container);
        $prop = $reflection->getProperty('instances');
        /** @var array<string, object> $value */
        $value = $prop->getValue($container);

        return $value;
    }

    /**
     * @return array<string, true>
     */
    private function readResolving(Container $container): array
    {
        $reflection = new \ReflectionClass($container);
        $prop = $reflection->getProperty('resolving');
        /** @var array<string, true> $value */
        $value = $prop->getValue($container);

        return $value;
    }
}

final class BoundResetTarget
{
}
