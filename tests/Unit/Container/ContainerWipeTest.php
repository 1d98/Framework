<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Container;

use Framework\Container\Container;
use Framework\Container\ContainerException;
use Framework\Container\ContainerInterface;
use Framework\Container\NotFoundException;
use Framework\Validation\Attribute\Validate;
use Framework\Validation\Rule\RuleRegistry;
use Framework\Validation\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;

#[CoversClass(Container::class)]
final class ContainerWipeTest extends TestCase
{
    public function testWipeClearsInstances(): void
    {
        $container = new Container();
        $container->set('a', static fn(): object => new stdClass());
        $container->set('b', static fn(): object => new stdClass());

        $container->get('a');
        $container->get('b');

        self::assertCount(2, $this->readInstances($container), 'precondition: both instances cached');

        $container->wipe();

        self::assertSame([], $this->readInstances($container));
    }

    public function testWipePreservesFactories(): void
    {
        $container = new Container();
        $container->set('svc', static fn(): object => new stdClass());

        self::assertTrue($container->has('svc'), 'precondition: factory registered');
        $container->get('svc');
        self::assertNotEmpty($this->readInstances($container), 'precondition: factory result cached');

        $container->wipe();

        self::assertTrue(
            $container->has('svc'),
            'wipe() is per-instance only; factory registration must survive',
        );
        self::assertSame([], $this->readInstances($container), 'cached singleton is dropped');
        self::assertInstanceOf(stdClass::class, $container->get('svc'), 'factory still resolves');
    }

    public function testWipePreservesBindings(): void
    {
        $container = new Container();
        $container->bind('bound', WipeTarget::class);

        self::assertTrue($container->has('bound'), 'precondition: binding registered');
        $container->get('bound');
        self::assertNotEmpty($this->readInstances($container), 'precondition: bound instance cached');

        $container->wipe();

        self::assertTrue(
            $container->has('bound'),
            'wipe() is per-instance only; binding registration must survive',
        );
        self::assertSame([], $this->readInstances($container), 'cached singleton is dropped');
        self::assertInstanceOf(WipeTarget::class, $container->get('bound'), 'binding still resolves');
    }

    public function testWipeClearsResolvingState(): void
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
            'cycle guard clears itself on the failure path',
        );

        $container->wipe();

        self::assertSame([], $this->readResolving($container));
    }

    public function testWipeDoesNotInvalidatePreviouslyResolvableId(): void
    {
        $container = new Container();
        $container->set('svc', static fn(): object => new stdClass());
        $container->get('svc');

        $container->wipe();

        self::assertInstanceOf(
            stdClass::class,
            $container->get('svc'),
            'wipe() preserves factory registration; the next get() must re-run the factory, not throw',
        );
    }

    public function testWipeIsIdempotent(): void
    {
        $container = new Container();
        $container->set('svc', static fn(): object => new stdClass());
        $container->bind('bound', WipeTarget::class);
        $container->get('svc');
        $container->get('bound');

        $container->wipe();
        $container->wipe();
        $container->wipe();

        self::assertSame([], $this->readInstances($container));
        self::assertNotEmpty($this->readBindings($container), 'bindings preserved across repeated wipe()');
        self::assertTrue($container->has('svc'), 'factory registration is preserved across repeated wipe()');
        self::assertTrue($container->has('bound'), 'binding registration is preserved across repeated wipe()');
    }

    public function testWipeOnEmptyContainerIsNoOp(): void
    {
        $container = new Container();

        $container->wipe();

        self::assertSame([], $this->readInstances($container));
        self::assertFalse($container->has('anything'));
    }

    public function testWipePreservesManualSet(): void
    {
        $container = new Container();
        $container->set('svc', static fn(): object => new stdClass());

        self::assertTrue($container->has('svc'));

        $container->wipe();

        self::assertTrue(
            $container->has('svc'),
            'wipe() is per-instance only; manual set() registration must survive',
        );
    }

    public function testWipePreservesBind(): void
    {
        $container = new Container();
        $container->bind('abstract', WipeTarget::class);

        self::assertTrue($container->has('abstract'));

        $container->wipe();

        self::assertTrue(
            $container->has('abstract'),
            'wipe() is per-instance only; bind() registration must survive',
        );
    }

    public function testResetDoesNotClearBindingsRegression(): void
    {
        $container = new Container();
        $container->bind('bound', WipeTarget::class);
        $container->set('factory', static fn(): object => new WipeTarget());
        $container->get('bound');
        $container->get('factory');

        $container->reset();

        self::assertTrue(
            $container->has('bound'),
            'regression: reset() must preserve bindings',
        );
        self::assertTrue(
            $container->has('factory'),
            'regression: reset() must preserve factories',
        );
        self::assertInstanceOf(WipeTarget::class, $container->get('bound'));
        self::assertInstanceOf(WipeTarget::class, $container->get('factory'));
    }

    public function testWipeIsAliasForResetOnPerInstanceState(): void
    {
        $wipeContainer = new Container();
        $resetContainer = new Container();

        foreach (['a', 'b', 'c'] as $id) {
            $wipeContainer->set($id, static fn(): object => new stdClass());
            $resetContainer->set($id, static fn(): object => new stdClass());
        }
        $wipeContainer->bind('bound', WipeTarget::class);
        $resetContainer->bind('bound', WipeTarget::class);

        foreach (['a', 'b', 'c', 'bound'] as $id) {
            $wipeContainer->get($id);
            $resetContainer->get($id);
        }

        $wipeContainer->wipe();
        $resetContainer->reset();

        self::assertSame(
            $this->readInstances($resetContainer),
            $this->readInstances($wipeContainer),
            'wipe() and reset() must produce identical per-instance state',
        );
        self::assertSame(
            $this->readBindings($resetContainer),
            $this->readBindings($wipeContainer),
            'wipe() and reset() must preserve bindings identically',
        );
        self::assertSame(
            $this->readResolving($resetContainer),
            $this->readResolving($wipeContainer),
            'wipe() and reset() must clear the cycle-guard map identically',
        );
    }

    public function testWipeDoesNotClearStaticTypeExistsCache(): void
    {
        $container = new Container();
        $container->bind('bound', WipeTarget::class);
        $container->get('bound');

        $cacheAfterGet = $this->readTypeExistsCache();
        self::assertArrayHasKey(WipeTarget::class, $cacheAfterGet, 'precondition: cache populated by resolution');

        $container->wipe();

        $cacheAfterWipe = $this->readTypeExistsCache();
        self::assertArrayHasKey(
            WipeTarget::class,
            $cacheAfterWipe,
            'wipe() is per-instance only and must NOT drop the process-wide typeExists cache',
        );
    }

    public function testWipeDoesNotClearValidatorReflectionCache(): void
    {
        Validator::clearCaches();

        $validator = new Validator(new RuleRegistry());
        $validator->validate(WipeProbeDto::class, ['name' => 'Alice', 'age' => 30]);

        $sizeBefore = $this->validatorReflectionCacheSize();
        self::assertGreaterThan(0, $sizeBefore, 'precondition: Validator reflection cache populated by validate()');

        (new Container())->wipe();

        self::assertSame(
            $sizeBefore,
            $this->validatorReflectionCacheSize(),
            'wipe() is per-instance only and must NOT touch Validator::$reflectionCache — use wipeGlobalCaches() for that',
        );
    }

    public function testWipeGlobalCachesClearsTypeExistsCache(): void
    {
        $container = new Container();
        $container->bind('bound', WipeTarget::class);
        $container->get('bound');

        self::assertArrayHasKey(
            WipeTarget::class,
            $this->readTypeExistsCache(),
            'precondition: typeExistsCache populated by resolution',
        );

        Container::wipeGlobalCaches();

        self::assertSame(
            [],
            $this->readTypeExistsCache(),
            'wipeGlobalCaches() must drop the static typeExists cache',
        );
    }

    public function testWipeGlobalCachesClearsReflectionCache(): void
    {
        $container = new Container();
        $container->get(WipeTarget::class);

        self::assertArrayHasKey(
            WipeTarget::class,
            $this->readReflectionCache(),
            'precondition: reflectionCache populated by autowire',
        );

        Container::wipeGlobalCaches();

        self::assertSame(
            [],
            $this->readReflectionCache(),
            'wipeGlobalCaches() must drop the static reflection cache',
        );
    }

    public function testWipeGlobalCachesClearsValidatorReflectionCache(): void
    {
        Validator::clearCaches();

        $validator = new Validator(new RuleRegistry());
        $validator->validate(WipeProbeDto::class, ['name' => 'Alice', 'age' => 30]);

        self::assertGreaterThan(
            0,
            $this->validatorReflectionCacheSize(),
            'precondition: Validator reflection cache populated by validate()',
        );

        Container::wipeGlobalCaches();

        self::assertSame(
            0,
            $this->validatorReflectionCacheSize(),
            'wipeGlobalCaches() must drop Validator::$reflectionCache — DTO schemas change across resets in long workers',
        );
    }

    public function testWipeGlobalCachesClearsValidatorClassExistsCache(): void
    {
        Validator::clearCaches();

        $validator = new Validator(new RuleRegistry());
        $validator->validate(WipeProbeDto::class, ['name' => 'Alice', 'age' => 30]);

        self::assertGreaterThan(
            0,
            $this->validatorClassExistsCacheSize(),
            'precondition: Validator classExistsCache populated by validate()',
        );

        Container::wipeGlobalCaches();

        self::assertSame(
            0,
            $this->validatorClassExistsCacheSize(),
            'wipeGlobalCaches() must drop Validator::$classExistsCache — otherwise class-existence lookups leak across resets',
        );
    }

    public function testWipeGlobalCachesLeavesPerInstanceStateAlone(): void
    {
        $container = new Container();
        $container->set('svc', static fn(): object => new stdClass());
        $container->bind('bound', WipeTarget::class);
        $container->get('svc');
        $container->get('bound');

        $instancesBefore = $this->readInstances($container);
        $bindingsBefore = $this->readBindings($container);

        Container::wipeGlobalCaches();

        self::assertSame(
            $instancesBefore,
            $this->readInstances($container),
            'wipeGlobalCaches() must not touch per-instance singletons',
        );
        self::assertSame(
            $bindingsBefore,
            $this->readBindings($container),
            'wipeGlobalCaches() must not touch per-instance bindings',
        );
    }

    public function testWipeGlobalCachesIsIdempotent(): void
    {
        $container = new Container();
        $container->get(WipeTarget::class);
        self::assertNotEmpty($this->readReflectionCache(), 'precondition: cache populated');

        Container::wipeGlobalCaches();
        Container::wipeGlobalCaches();
        Container::wipeGlobalCaches();

        self::assertSame([], $this->readReflectionCache());
        self::assertSame([], $this->readTypeExistsCache());
    }

    public function testWipeGlobalCachesIsCallableStaticallyOnInstance(): void
    {
        $container = new Container();
        $container->get(WipeTarget::class);

        $container::wipeGlobalCaches();

        self::assertSame(
            [],
            $this->readReflectionCache(),
            'wipeGlobalCaches() must be callable both as Container::wipeGlobalCaches() and $container::wipeGlobalCaches()',
        );
    }

    public function testClearCachesIsAliasForWipeGlobalCaches(): void
    {
        $container = new Container();
        $container->get(WipeTarget::class);
        $container->bind('bound', WipeTarget::class);
        $container->get('bound');

        $validator = new Validator(new RuleRegistry());
        $validator->validate(WipeProbeDto::class, ['name' => 'Alice', 'age' => 30]);

        self::assertArrayHasKey(WipeTarget::class, $this->readReflectionCache());
        self::assertArrayHasKey(WipeTarget::class, $this->readTypeExistsCache());
        self::assertGreaterThan(0, $this->validatorReflectionCacheSize());

        Container::clearCaches();

        self::assertSame([], $this->readReflectionCache(), 'clearCaches() alias must drop Container reflection cache');
        self::assertSame([], $this->readTypeExistsCache(), 'clearCaches() alias must drop Container typeExists cache');
        self::assertSame(0, $this->validatorReflectionCacheSize(), 'clearCaches() alias must drop Validator cache');
    }

    /**
     * @return array<string, object>
     */
    private function readInstances(Container $container): array
    {
        $prop = (new \ReflectionClass($container))->getProperty('instances');
        /** @var array<string, object> $value */
        $value = $prop->getValue($container);

        return $value;
    }

    /**
     * @return array<string, class-string|\Closure(ContainerInterface): mixed>
     */
    private function readBindings(Container $container): array
    {
        $prop = (new \ReflectionClass($container))->getProperty('bindings');
        /** @var array<string, class-string|\Closure(ContainerInterface): mixed> $value */
        $value = $prop->getValue($container);

        return $value;
    }

    /**
     * @return array<string, true>
     */
    private function readResolving(Container $container): array
    {
        $prop = (new \ReflectionClass($container))->getProperty('resolving');
        /** @var array<string, true> $value */
        $value = $prop->getValue($container);

        return $value;
    }

    /**
     * @return array<string, bool>
     */
    private function readTypeExistsCache(): array
    {
        $prop = (new \ReflectionClass(Container::class))->getProperty('typeExistsCache');
        /** @var array<string, bool> $value */
        $value = $prop->getValue();

        return $value;
    }

    /**
     * @return array<class-string, \ReflectionClass<object>>
     */
    private function readReflectionCache(): array
    {
        $prop = (new \ReflectionClass(Container::class))->getProperty('reflectionCache');
        /** @var array<class-string, \ReflectionClass<object>> $value */
        $value = $prop->getValue();

        return $value;
    }

    private function validatorReflectionCacheSize(): int
    {
        $prop = new ReflectionProperty(Validator::class, 'reflectionCache');
        /** @var array<class-string, \ReflectionClass<object>> $value */
        $value = $prop->getValue();

        return count($value);
    }

    private function validatorClassExistsCacheSize(): int
    {
        $prop = new ReflectionProperty(Validator::class, 'classExistsCache');
        /** @var array<string, bool> $value */
        $value = $prop->getValue();

        return count($value);
    }

    protected function tearDown(): void
    {
        Validator::clearCaches();
    }
}

final class WipeTarget
{
}

final class WipeProbeDto
{
    public function __construct(
        #[Validate('required|string')]
        public ?string $name = null,
        #[Validate('required|integer|min:0|max:150')]
        public ?int $age = null,
    ) {
    }
}
