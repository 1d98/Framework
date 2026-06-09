<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Validation\Rule;

use Framework\Validation\Rule\RegexRule;
use Framework\Validation\Rule\MinRule;
use Framework\Validation\Rule\MaxRule;
use Framework\Validation\Rule\LengthRule;
use Framework\Validation\Rule\BetweenRule;
use Framework\Validation\Rule\InRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegexRule::class)]
#[CoversClass(MinRule::class)]
#[CoversClass(MaxRule::class)]
#[CoversClass(LengthRule::class)]
#[CoversClass(BetweenRule::class)]
#[CoversClass(InRule::class)]
final class ParametricRulesTest extends TestCase
{
    public function testRegexMatchesPattern(): void
    {
        $rule = new RegexRule(pattern: '/^[a-z]+$/');
        self::assertNull($rule->validate('hello', []));
        self::assertNotNull($rule->validate('Hello', []));
        self::assertNotNull($rule->validate('hello123', []));
    }

    public function testRegexSkipsNull(): void
    {
        $rule = new RegexRule(pattern: '/.*/');
        self::assertNull($rule->validate(null, []));
    }

    public function testRegexRejectsMissingPattern(): void
    {
        $rule = new RegexRule();
        self::assertNotNull($rule->validate('hello', []));
    }

    public function testRegexParams(): void
    {
        $rule = new RegexRule(pattern: '/^\d+$/');
        self::assertSame(['pattern' => '/^\d+$/'], $rule->params());
    }

    public function testMinRuleAppliesToStringLength(): void
    {
        $rule = new MinRule(min: 3);
        self::assertNull($rule->validate('abc', []));
        self::assertNull($rule->validate('abcd', []));
        self::assertNotNull($rule->validate('ab', []));
    }

    public function testMinRuleAppliesToIntValue(): void
    {
        $rule = new MinRule(min: 18);
        self::assertNull($rule->validate(18, []));
        self::assertNull($rule->validate(25, []));
        self::assertNotNull($rule->validate(17, []));
    }

    public function testMinRuleAppliesToArrayCount(): void
    {
        $rule = new MinRule(min: 2);
        self::assertNull($rule->validate(['a', 'b'], []));
        self::assertNotNull($rule->validate(['a'], []));
    }

    public function testMinRuleSkipsNull(): void
    {
        $rule = new MinRule(min: 5);
        self::assertNull($rule->validate(null, []));
    }

    public function testMaxRuleAppliesToStringLength(): void
    {
        $rule = new MaxRule(max: 5);
        self::assertNull($rule->validate('abc', []));
        self::assertNotNull($rule->validate('abcdef', []));
    }

    public function testMaxRuleAppliesToIntValue(): void
    {
        $rule = new MaxRule(max: 100);
        self::assertNull($rule->validate(100, []));
        self::assertNotNull($rule->validate(101, []));
    }

    public function testMaxRuleAppliesToArrayCount(): void
    {
        $rule = new MaxRule(max: 2);
        self::assertNull($rule->validate(['a', 'b'], []));
        self::assertNotNull($rule->validate(['a', 'b', 'c'], []));
    }

    public function testMaxRuleSkipsNull(): void
    {
        $rule = new MaxRule(max: 5);
        self::assertNull($rule->validate(null, []));
    }

    public function testLengthRuleExactLength(): void
    {
        $rule = new LengthRule(length: 5);
        self::assertNull($rule->validate('abcde', []));
        self::assertNotNull($rule->validate('abcd', []));
        self::assertNotNull($rule->validate('abcdef', []));
    }

    public function testLengthRuleRange(): void
    {
        $rule = new LengthRule(min: 3, max: 5);
        self::assertNull($rule->validate('abc', []));
        self::assertNull($rule->validate('abcde', []));
        self::assertNotNull($rule->validate('ab', []));
        self::assertNotNull($rule->validate('abcdef', []));
    }

    public function testBetweenRuleInclusiveRange(): void
    {
        $rule = new BetweenRule(min: 18, max: 65);
        self::assertNull($rule->validate(18, []));
        self::assertNull($rule->validate(65, []));
        self::assertNull($rule->validate(30, []));
        self::assertNotNull($rule->validate(17, []));
        self::assertNotNull($rule->validate(66, []));
    }

    public function testBetweenRuleSkipsNull(): void
    {
        $rule = new BetweenRule(min: 0, max: 100);
        self::assertNull($rule->validate(null, []));
    }

    public function testInRuleAcceptsListedValue(): void
    {
        $rule = new InRule(values: ['red', 'green', 'blue']);
        self::assertNull($rule->validate('red', []));
        self::assertNull($rule->validate('blue', []));
    }

    public function testInRuleRejectsUnlistedValue(): void
    {
        $rule = new InRule(values: ['red', 'green', 'blue']);
        self::assertNotNull($rule->validate('yellow', []));
    }

    public function testInRuleIsStrict(): void
    {
        $rule = new InRule(values: [1, 2, 3]);
        self::assertNotNull($rule->validate('1', []));
    }

    public function testInRuleSkipsNull(): void
    {
        $rule = new InRule(values: ['a', 'b']);
        self::assertNull($rule->validate(null, []));
    }
}
