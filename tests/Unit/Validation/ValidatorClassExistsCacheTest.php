<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Validation;

use Framework\Validation\Attribute\Validate;
use Framework\Validation\Rule\RuleRegistry;
use Framework\Validation\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

#[CoversClass(Validator::class)]
final class ValidatorClassExistsCacheTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator(new RuleRegistry());
        Validator::clearCaches();
    }

    protected function tearDown(): void
    {
        Validator::clearCaches();
    }

    public function testRepeatedValidateDoesNotGrowClassExistsCache(): void
    {
        $this->validator->validate(CacheProbeDto::class, [
            'name' => 'Alice',
            'age' => 30,
        ]);

        $sizeAfterFirst = $this->classExistsCacheSize();

        $this->validator->validate(CacheProbeDto::class, [
            'name' => 'Bob',
            'age' => 25,
        ]);

        $this->validator->validate(CacheProbeDto::class, [
            'name' => 'Carol',
            'age' => 40,
        ]);

        self::assertSame(
            $sizeAfterFirst,
            $this->classExistsCacheSize(),
            'Repeated validate() on the same DTO must not invoke class_exists again — the cache is memoized.',
        );
    }

    public function testClearCachesResetsClassExistsAndReflectionCaches(): void
    {
        $this->validator->validate(CacheProbeDto::class, ['name' => 'Alice', 'age' => 30]);

        self::assertGreaterThan(0, $this->classExistsCacheSize(), 'precondition: cache populated by validate()');
        self::assertGreaterThan(0, $this->reflectionCacheSize(), 'precondition: reflection cache populated by validate()');

        Validator::clearCaches();

        self::assertSame(0, $this->classExistsCacheSize(), 'class_exists cache is cleared');
        self::assertSame(0, $this->reflectionCacheSize(), 'reflection cache is cleared');
    }

    public function testClassExistsCachedReturnsTrueForExistingClass(): void
    {
        Validator::clearCaches();

        self::assertTrue($this->invokeClassExistsCached(CacheProbeDto::class));
        self::assertArrayHasKey(CacheProbeDto::class, $this->classExistsCacheSnapshot());
    }

    public function testClassExistsCachedReturnsFalseForUnknownClass(): void
    {
        Validator::clearCaches();

        $unknown = 'Framework\\Validation\\DoesNotExist_' . bin2hex(random_bytes(6));

        self::assertFalse($this->invokeClassExistsCached($unknown));
        self::assertArrayHasKey($unknown, $this->classExistsCacheSnapshot());
        self::assertFalse(
            $this->classExistsCacheSnapshot()[$unknown],
            'Negative result is memoized, not re-probed on the next call.',
        );
    }

    public function testClassExistsCachedServesFromCacheOnSecondCall(): void
    {
        Validator::clearCaches();

        $first = $this->invokeClassExistsCached(CacheProbeDto::class);
        $snapshotAfterFirst = $this->classExistsCacheSnapshot();

        $second = $this->invokeClassExistsCached(CacheProbeDto::class);
        $snapshotAfterSecond = $this->classExistsCacheSnapshot();

        self::assertTrue($first);
        self::assertTrue($second);
        self::assertSame(
            $snapshotAfterFirst,
            $snapshotAfterSecond,
            'A second call must be served from cache — the snapshot must not change.',
        );
    }

    public function testClearCachesForcesReLookupOfSameClass(): void
    {
        Validator::clearCaches();

        self::assertTrue($this->invokeClassExistsCached(CacheProbeDto::class));
        self::assertArrayHasKey(CacheProbeDto::class, $this->classExistsCacheSnapshot());

        Validator::clearCaches();

        self::assertSame([], $this->classExistsCacheSnapshot());
        self::assertTrue($this->invokeClassExistsCached(CacheProbeDto::class));
        self::assertArrayHasKey(CacheProbeDto::class, $this->classExistsCacheSnapshot());
    }

    /**
     * @return array<string, bool>
     */
    private function classExistsCacheSnapshot(): array
    {
        $ref = new ReflectionProperty(Validator::class, 'classExistsCache');
        /** @var array<string, bool> $value */
        $value = $ref->getValue();

        return $value;
    }

    private function classExistsCacheSize(): int
    {
        return count($this->classExistsCacheSnapshot());
    }

    private function reflectionCacheSize(): int
    {
        return count($this->readStaticProperty('reflectionCache'));
    }

    /**
     * @return array<string, mixed>
     */
    private function readStaticProperty(string $name): array
    {
        $ref = new ReflectionClass(Validator::class);
        $prop = $ref->getProperty($name);
        /** @var array<string, mixed> $value */
        $value = $prop->getValue();

        return $value;
    }

    private function invokeClassExistsCached(string $fqcn): bool
    {
        $ref = new ReflectionMethod(Validator::class, 'classExistsCached');

        return (bool) $ref->invoke(null, $fqcn);
    }
}

final class CacheProbeDto
{
    public function __construct(
        #[Validate('required|string')]
        public ?string $name = null,
        #[Validate('required|integer|min:0|max:150')]
        public ?int $age = null,
    ) {
    }
}
