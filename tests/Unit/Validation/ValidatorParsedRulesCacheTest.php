<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Validation;

use Framework\Validation\Attribute\Validate;
use Framework\Validation\Rule\MaxRule;
use Framework\Validation\Rule\MinRule;
use Framework\Validation\Rule\RuleInterface;
use Framework\Validation\Rule\RuleRegistry;
use Framework\Validation\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Validator::class)]
#[CoversClass(Validate::class)]
final class ValidatorParsedRulesCacheTest extends TestCase
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

    public function testThousandValidatesTriggerOneParsePerDistinctString(): void
    {
        $this->validator->validate(MemoizationDto::class, [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'age' => 30,
        ]);

        $statsAfterFirst = Validator::memoizationStats();
        self::assertSame(3, $statsAfterFirst['parseRuleSpecsCalls'], 'precondition: one parse per attribute on the first validate()');
        self::assertSame(0, $statsAfterFirst['parsedRulesCacheHits'], 'precondition: no hits on the first validate()');
        self::assertSame(3, $statsAfterFirst['parsedRulesCacheSize'], 'precondition: three distinct string DSLs cached');

        for ($i = 0; $i < 1000; $i++) {
            $this->validator->validate(MemoizationDto::class, [
                'name' => 'Alice-' . $i,
                'email' => "user{$i}@example.com",
                'age' => 20 + ($i % 50),
            ]);
        }

        $stats = Validator::memoizationStats();
        self::assertSame(3, $stats['parseRuleSpecsCalls'], '1000 re-binds must not re-parse — three distinct string DSLs, parsed once each');
        self::assertSame(3000, $stats['parsedRulesCacheHits'], '1000 binds × 3 attributes = 3000 cache hits');
        self::assertSame(3, $stats['parsedRulesCacheSize'], 'cache size stays at the number of distinct string DSLs');
    }

    public function testCacheIsSharedAcrossAttributeInstancesWithSameDsl(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->validator->validate(SharedDslDto::class, [
                'a' => 'shared',
                'b' => 'also-shared',
            ]);
        }

        $stats = Validator::memoizationStats();
        self::assertSame(1, $stats['parseRuleSpecsCalls'], 'two attributes with the same string DSL share one parsed entry');
        self::assertSame(1, $stats['parsedRulesCacheSize'], 'cache holds one entry for the shared DSL');
        self::assertSame(9, $stats['parsedRulesCacheHits'], '5 binds × 2 attributes − 1 cold parse = 9 hits');
    }

    public function testArrayFormRulesAreParsedFreshEachBind(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->validator->validate(ArrayRulesDto::class, [
                'score' => $i,
            ]);
        }

        $stats = Validator::memoizationStats();
        self::assertSame(10, $stats['parseRuleSpecsCalls'], 'array form has no stable key; parsed per bind by design');
        self::assertSame(0, $stats['parsedRulesCacheHits'], 'array form never hits the cache');
        self::assertSame(0, $stats['parsedRulesCacheSize'], 'array form is not stored in the cache');
    }

    public function testClearCachesResetsParsedRulesCacheAndCounters(): void
    {
        $this->validator->validate(MemoizationDto::class, [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'age' => 30,
        ]);

        for ($i = 0; $i < 50; $i++) {
            $this->validator->validate(MemoizationDto::class, [
                'name' => 'Alice',
                'email' => 'alice@example.com',
                'age' => 30,
            ]);
        }

        $stats = Validator::memoizationStats();
        self::assertSame(3, $stats['parseRuleSpecsCalls']);
        self::assertSame(150, $stats['parsedRulesCacheHits']);
        self::assertSame(3, $stats['parsedRulesCacheSize']);

        Validator::clearCaches();

        $stats = Validator::memoizationStats();
        self::assertSame(0, $stats['parseRuleSpecsCalls'], 'counters reset by clearCaches()');
        self::assertSame(0, $stats['parsedRulesCacheHits'], 'counters reset by clearCaches()');
        self::assertSame(0, $stats['parsedRulesCacheSize'], 'cache emptied by clearCaches()');

        $this->validator->validate(MemoizationDto::class, [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'age' => 30,
        ]);

        $stats = Validator::memoizationStats();
        self::assertSame(3, $stats['parseRuleSpecsCalls'], 'post-clear, the next validate() cold-parses every distinct DSL again');
        self::assertSame(0, $stats['parsedRulesCacheHits']);
        self::assertSame(3, $stats['parsedRulesCacheSize']);
    }

    public function testMemoizationStatsShapeAndDefaults(): void
    {
        $stats = Validator::memoizationStats();
        self::assertSame(
            ['parseRuleSpecsCalls', 'parsedRulesCacheHits', 'parsedRulesCacheSize'],
            array_keys($stats),
            'memoizationStats() must expose the three documented keys in the documented order',
        );
        self::assertSame(0, $stats['parseRuleSpecsCalls']);
        self::assertSame(0, $stats['parsedRulesCacheHits']);
        self::assertSame(0, $stats['parsedRulesCacheSize']);
    }

    public function testFirstValidateColdParsesAllDistinctStrings(): void
    {
        $this->validator->validate(MemoizationDto::class, [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'age' => 30,
        ]);

        $stats = Validator::memoizationStats();
        self::assertSame(3, $stats['parseRuleSpecsCalls']);
        self::assertSame(0, $stats['parsedRulesCacheHits']);
        self::assertSame(3, $stats['parsedRulesCacheSize']);
    }

    public function testContainerWipeGlobalCachesClearsValidatorParsedRulesCache(): void
    {
        $this->validator->validate(MemoizationDto::class, [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'age' => 30,
        ]);

        for ($i = 0; $i < 10; $i++) {
            $this->validator->validate(MemoizationDto::class, [
                'name' => 'Alice',
                'email' => 'alice@example.com',
                'age' => 30,
            ]);
        }

        $stats = Validator::memoizationStats();
        self::assertSame(3, $stats['parseRuleSpecsCalls']);
        self::assertSame(30, $stats['parsedRulesCacheHits']);
        self::assertSame(3, $stats['parsedRulesCacheSize']);

        \Framework\Container\Container::wipeGlobalCaches();

        $stats = Validator::memoizationStats();
        self::assertSame(0, $stats['parseRuleSpecsCalls']);
        self::assertSame(0, $stats['parsedRulesCacheHits']);
        self::assertSame(0, $stats['parsedRulesCacheSize']);
    }
}

final class MemoizationDto
{
    public function __construct(
        #[Validate('required|string|min:3|max:50')]
        public ?string $name = null,
        #[Validate('required|email')]
        public ?string $email = null,
        #[Validate('required|integer|min:18|max:120')]
        public ?int $age = null,
    ) {
    }
}

final class SharedDslDto
{
    public function __construct(
        #[Validate('required|string|min:1')]
        public ?string $a = null,
        #[Validate('required|string|min:1')]
        public ?string $b = null,
    ) {
    }
}

final class ArrayRulesDto
{
    public function __construct(
        #[Validate([new MinRule(min: 0), new MaxRule(max: 100)])]
        public ?int $score = null,
    ) {
    }
}
