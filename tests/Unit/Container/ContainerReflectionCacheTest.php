<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Container;

use Framework\Container\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(Container::class)]
final class ContainerReflectionCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::clearCaches();
        parent::tearDown();
    }

    public function testAutowirePopulatesReflectionCache(): void
    {
        $container = new Container();
        $container->get(ReflectionCacheTarget::class);

        $cache = $this->readReflectionCache();

        self::assertArrayHasKey(
            ReflectionCacheTarget::class,
            $cache,
            'autowire() must memoize the ReflectionClass for resolved FQCNs',
        );
    }

    public function testRepeatedForgiveAndGetReusesSameReflectionInstance(): void
    {
        $container = new Container();

        $first = $this->readReflectionCache()[ReflectionCacheTarget::class] ?? null;
        $container->get(ReflectionCacheTarget::class);
        $first = $this->readReflectionCache()[ReflectionCacheTarget::class];

        for ($i = 0; $i < 100; $i++) {
            $container->forget(ReflectionCacheTarget::class);
            $container->get(ReflectionCacheTarget::class);
        }

        $after = $this->readReflectionCache()[ReflectionCacheTarget::class];

        self::assertSame(
            $first,
            $after,
            'forget() must not drop the cached ReflectionClass — only wipe()/clearCaches() should',
        );
    }

    public function testRepeatedGetReturnsSameCachedReflection(): void
    {
        $container = new Container();
        $container->get(ReflectionCacheTarget::class);
        $first = $this->readReflectionCache()[ReflectionCacheTarget::class];

        $container->get(ReflectionCacheTarget::class);
        $container->get(ReflectionCacheTarget::class);
        $container->get(ReflectionCacheTarget::class);

        self::assertSame(
            $first,
            $this->readReflectionCache()[ReflectionCacheTarget::class],
            'subsequent gets must hit the reflection cache',
        );
    }

    public function testCacheKeyIsClassStringOnly(): void
    {
        $container = new Container();
        $container->get(ReflectionCacheTarget::class);

        $cache = $this->readReflectionCache();

        self::assertCount(1, $cache, 'exactly one FQCN cached for a single autowire() call');
        self::assertArrayHasKey(ReflectionCacheTarget::class, $cache);
    }

    public function testAutowireFailureDoesNotPolluteCache(): void
    {
        $container = new Container();

        try {
            $container->get('Framework\\Container\\DoesNotExistEver');
            self::fail('Expected NotFoundException');
        } catch (\Framework\Container\NotFoundException) {
        }

        $cache = $this->readReflectionCache();

        self::assertArrayNotHasKey(
            'Framework\\Container\\DoesNotExistEver',
            $cache,
            'failed autowire must not cache the (non-existent) class',
        );
    }

    public function testWipeDoesNotClearReflectionCache(): void
    {
        $container = new Container();
        $container->get(ReflectionCacheTarget::class);

        $cacheAfterGet = $this->readReflectionCache();
        self::assertArrayHasKey(ReflectionCacheTarget::class, $cacheAfterGet, 'precondition: cache populated');

        $container->wipe();

        self::assertArrayHasKey(
            ReflectionCacheTarget::class,
            $this->readReflectionCache(),
            'wipe() is per-instance only; the reflection cache is process-wide and must survive',
        );
    }

    public function testWipeGlobalCachesClearsReflectionCache(): void
    {
        $container = new Container();
        $container->get(ReflectionCacheTarget::class);

        $cacheAfterGet = $this->readReflectionCache();
        self::assertArrayHasKey(ReflectionCacheTarget::class, $cacheAfterGet, 'precondition: cache populated');

        Container::wipeGlobalCaches();

        self::assertSame(
            [],
            $this->readReflectionCache(),
            'wipeGlobalCaches() must drop the reflection cache alongside the typeExists cache',
        );
    }

    public function testClearCachesDropsReflectionCache(): void
    {
        $container = new Container();
        $container->get(ReflectionCacheTarget::class);

        self::assertNotEmpty($this->readReflectionCache(), 'precondition: cache populated');

        Container::clearCaches();

        self::assertSame(
            [],
            $this->readReflectionCache(),
            'clearCaches() must drop the reflection cache',
        );
    }

    public function testReflectionCacheSurvivesReset(): void
    {
        $container = new Container();
        $container->get(ReflectionCacheTarget::class);
        $first = $this->readReflectionCache()[ReflectionCacheTarget::class];

        $container->reset();

        $after = $this->readReflectionCache()[ReflectionCacheTarget::class] ?? null;

        self::assertSame(
            $first,
            $after,
            'reset() is for per-instance state only; reflection cache is process-wide and must persist',
        );
    }

    public function testForgetAloneDoesNotClearReflectionCache(): void
    {
        $container = new Container();
        $container->get(ReflectionCacheTarget::class);
        $first = $this->readReflectionCache()[ReflectionCacheTarget::class];

        $container->forget(ReflectionCacheTarget::class);

        self::assertSame(
            $first,
            $this->readReflectionCache()[ReflectionCacheTarget::class] ?? null,
            'forget() removes the resolved instance but must preserve the class reflection',
        );
    }

    public function testPerfDeltaWithinBudgetOver1000Iterations(): void
    {
        $container = new Container();

        $start = microtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $container->forget(ReflectionCacheTarget::class);
            $container->get(ReflectionCacheTarget::class);
        }
        $elapsed = microtime(true) - $start;
        $perIterUs = ($elapsed * 1_000_000) / 1000;

        fwrite(STDERR, sprintf(
            "\n[reflection-cache] 1000 iter = %.4f s (%.2f us/iter)\n",
            $elapsed,
            $perIterUs,
        ));

        self::assertGreaterThan(0.0, $elapsed);

        self::assertLessThan(
            50.0,
            $perIterUs,
            sprintf(
                'autowire hot path is taking %.2f us/iter — expected sub-microsecond on PHP 8.5',
                $perIterUs,
            ),
        );
    }

    public function testWipeGlobalCachesClearsBothTypeExistsAndReflectionCaches(): void
    {
        $container = new Container();
        $container->get(ReflectionCacheTarget::class);

        $reflectionBefore = $this->readReflectionCache();
        $typeExistsBefore = $this->readTypeExistsCache();
        self::assertNotEmpty($reflectionBefore, 'precondition: reflection cache populated');
        self::assertNotEmpty($typeExistsBefore, 'precondition: typeExists cache populated');

        Container::wipeGlobalCaches();

        self::assertSame([], $this->readReflectionCache(), 'wipeGlobalCaches() must clear reflectionCache');
        self::assertSame([], $this->readTypeExistsCache(), 'wipeGlobalCaches() must clear typeExistsCache');
    }

    /**
     * @return array<class-string, ReflectionClass<object>>
     */
    private function readReflectionCache(): array
    {
        $prop = (new ReflectionClass(Container::class))->getProperty('reflectionCache');
        /** @var array<class-string, ReflectionClass<object>> $value */
        $value = $prop->getValue();

        return $value;
    }

    /**
     * @return array<string, bool>
     */
    private function readTypeExistsCache(): array
    {
        $prop = (new ReflectionClass(Container::class))->getProperty('typeExistsCache');
        /** @var array<string, bool> $value */
        $value = $prop->getValue();

        return $value;
    }
}

final class ReflectionCacheTarget
{
    public function __construct()
    {
    }
}
