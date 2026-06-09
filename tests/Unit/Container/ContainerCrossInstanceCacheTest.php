<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Container;

use Framework\Container\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

/**
 * Documenting the cross-instance contract (R6 split): the static
 * caches (`$typeExistsCache`, `$reflectionCache`) are `private static`
 * and therefore shared across every `Container` instance in the process.
 *
 * `wipe()` is per-instance only — it does NOT touch the static caches,
 * so calling `wipe()` on container B does not invalidate the
 * memoization container A populated. This is the safe default in
 * long-running workers that build a fresh container per request and
 * in PHPUnit fixtures that share a process across two test cases.
 *
 * `wipeGlobalCaches()` is process-wide — it explicitly drops the
 * memoization for every container in the process. Use it deliberately.
 * See `src/Container/Container.php` (class-level docblock) and
 * `docs/container.md` ("Static caches and process-wide state").
 */
#[CoversClass(Container::class)]
final class ContainerCrossInstanceCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::clearCaches();
        parent::tearDown();
    }

    public function testWipeOnContainerBDoesNotDropReflectionCachePopulatedByContainerA(): void
    {
        Container::clearCaches();

        $containerA = new Container();
        $containerA->bind('bound-a', CrossInstanceTarget::class);
        $containerA->get('bound-a');

        $reflectionAfterA = $this->readReflectionCache();
        self::assertArrayHasKey(
            CrossInstanceTarget::class,
            $reflectionAfterA,
            'precondition: container A populated $reflectionCache for CrossInstanceTarget',
        );

        $containerB = new Container();
        $containerB->wipe();

        self::assertArrayHasKey(
            CrossInstanceTarget::class,
            $this->readReflectionCache(),
            'wipe() on B must NOT drop the reflection cache populated by container A — wipe is per-instance only',
        );
    }

    public function testWipeOnContainerBDoesNotDropTypeExistsCachePopulatedByContainerA(): void
    {
        Container::clearCaches();

        $containerA = new Container();
        $containerA->has(stdClass::class);

        self::assertArrayHasKey(
            stdClass::class,
            $this->readTypeExistsCache(),
            'precondition: container A populated $typeExistsCache via has()',
        );

        $containerB = new Container();
        $containerB->wipe();

        self::assertArrayHasKey(
            stdClass::class,
            $this->readTypeExistsCache(),
            'wipe() on B must NOT drop the typeExists cache populated by container A — wipe is per-instance only',
        );
    }

    public function testWipeOnContainerBDoesNotForceContainerAToReRunClassExists(): void
    {
        Container::clearCaches();

        $containerA = new Container();
        $containerA->get(CrossInstanceTarget::class);

        $containerB = new Container();
        $containerB->wipe();

        $cached = $this->readReflectionCache()[CrossInstanceTarget::class] ?? null;
        self::assertInstanceOf(
            ReflectionClass::class,
            $cached,
            'container A must still be able to use the cached ReflectionClass after B wipe() — no re-build on the hot path',
        );

        $containerA->get(CrossInstanceTarget::class);

        self::assertSame(
            $cached,
            $this->readReflectionCache()[CrossInstanceTarget::class] ?? null,
            'second get() on A must hit the same cached ReflectionClass — class_exists was not re-run',
        );
    }

    public function testWipeGlobalCachesOnContainerBDropsReflectionCachePopulatedByContainerA(): void
    {
        Container::clearCaches();

        $containerA = new Container();
        $containerA->bind('bound-a', CrossInstanceTarget::class);
        $containerA->get('bound-a');

        $reflectionAfterA = $this->readReflectionCache();
        self::assertArrayHasKey(
            CrossInstanceTarget::class,
            $reflectionAfterA,
            'precondition: container A populated $reflectionCache for CrossInstanceTarget',
        );

        $containerB = new Container();
        $containerB::wipeGlobalCaches();

        self::assertSame(
            [],
            $this->readReflectionCache(),
            'wipeGlobalCaches() on B must drop the reflection cache populated by container A — process-wide by definition',
        );
        self::assertSame(
            [],
            $this->readTypeExistsCache(),
            'wipeGlobalCaches() on B must drop the typeExists cache populated by container A — process-wide by definition',
        );
    }

    public function testReflectionCacheIsVisibleAcrossInstancesEvenBeforeAnyWipe(): void
    {
        Container::clearCaches();

        $containerA = new Container();
        $containerA->get(CrossInstanceTarget::class);
        $cachedFromA = $this->readReflectionCache()[CrossInstanceTarget::class];

        $containerB = new Container();

        self::assertSame(
            $cachedFromA,
            $this->readReflectionCache()[CrossInstanceTarget::class] ?? null,
            'documenting the cross-instance contract: container B observes A\'s memoization',
        );
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

final class CrossInstanceTarget
{
    public function __construct()
    {
    }
}
