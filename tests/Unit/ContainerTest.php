<?php

declare(strict_types=1);

namespace Framework\Tests\Unit;

use Framework\Container\Container;
use Framework\Container\ContainerException;
use Framework\Container\ContainerInterface;
use Framework\Container\NotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Container::class)]
final class ContainerTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        self::assertInstanceOf(ContainerInterface::class, new Container());
    }

    public function testGetThrowsForUnknownService(): void
    {
        $this->expectException(NotFoundException::class);

        (new Container())->get('NonExistent\\Class\\Name');
    }

    public function testHasReturnsTrueForRegisteredClass(): void
    {
        $container = new Container();

        self::assertTrue($container->has(Container::class));
        self::assertFalse($container->has('NonExistent\\Class\\Name'));
    }

    public function testSetAndGetReturnsFactoryResult(): void
    {
        $container = new Container();
        $container->set('service', static fn(): object => new \stdClass());

        $first = $container->get('service');
        $second = $container->get('service');

        self::assertInstanceOf(\stdClass::class, $first);
        self::assertSame($first, $second, 'Container should return singleton instance');
    }

    public function testSetOverwritesPreviousFactoryAndResetsCache(): void
    {
        $container = new Container();

        $first = new \stdClass();
        $container->set('svc', static fn(): object => $first);
        self::assertSame($first, $container->get('svc'));

        $second = new \stdClass();
        $container->set('svc', static fn(): object => $second);
        self::assertSame($second, $container->get('svc'));
        self::assertNotSame($first, $container->get('svc'));
    }

    public function testFactoryReceivesContainer(): void
    {
        $container = new Container();
        $received = null;
        $container->set('svc', function (ContainerInterface $c) use (&$received): object {
            $received = $c;
            return new \stdClass();
        });

        $container->get('svc');

        self::assertSame($container, $received);
    }

    public function testGetReturnsSameSingletonAcrossCalls(): void
    {
        $container = new Container();
        $container->set('svc', static fn(): object => new \stdClass());

        self::assertSame($container->get('svc'), $container->get('svc'));
    }

    public function testAutowireResolvesClassWithoutConstructor(): void
    {
        $instance = (new Container())->get(AutowireTargetNoCtor::class);

        self::assertInstanceOf(AutowireTargetNoCtor::class, $instance);
    }

    public function testAutowireResolvesClassWithScalarDefaults(): void
    {
        $instance = (new Container())->get(AutowireTargetScalars::class);

        self::assertInstanceOf(AutowireTargetScalars::class, $instance);
        self::assertSame('default', $instance->name);
        self::assertSame(0, $instance->count);
    }

    public function testAutowireResolvesClassDependencies(): void
    {
        $instance = (new Container())->get(AutowireTargetDeps::class);

        self::assertInstanceOf(AutowireTargetDeps::class, $instance);
        self::assertInstanceOf(AutowireTargetScalars::class, $instance->scalars);
    }

    public function testAutowireFailsOnNonAutowireableScalarWithoutDefault(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot autowire parameter $value');

        (new Container())->get(AutowireTargetFails::class);
    }

    public function testAutowireAllowsNullForNullableTypeWithNullDefault(): void
    {
        $instance = (new Container())->get(AutowireTargetNullable::class);

        self::assertInstanceOf(AutowireTargetNullable::class, $instance);
        self::assertNull($instance->maybe);
    }
}

final class AutowireTargetNoCtor
{
}

final class AutowireTargetScalars
{
    public function __construct(
        public string $name = 'default',
        public int $count = 0,
    ) {
    }
}

final class AutowireTargetDeps
{
    public function __construct(
        public AutowireTargetScalars $scalars,
    ) {
    }
}

final class AutowireTargetFails
{
    public function __construct(
        public string $value,
    ) {
    }
}

final class AutowireTargetNullable
{
    public function __construct(
        public ?AutowireTargetScalars $maybe = null,
    ) {
    }
}
