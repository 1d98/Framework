<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Validation\Rule;

use Framework\Validation\Rule\StringRule;
use Framework\Validation\Rule\IntegerRule;
use Framework\Validation\Rule\FloatRule;
use Framework\Validation\Rule\BooleanRule;
use Framework\Validation\Rule\ArrayRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StringRule::class)]
#[CoversClass(IntegerRule::class)]
#[CoversClass(FloatRule::class)]
#[CoversClass(BooleanRule::class)]
#[CoversClass(ArrayRule::class)]
final class TypeRulesTest extends TestCase
{
    public function testStringRuleAcceptsString(): void
    {
        $rule = new StringRule();
        self::assertNull($rule->validate('hello', []));
        self::assertNull($rule->validate('', []));
    }

    public function testStringRuleSkipsNull(): void
    {
        $rule = new StringRule();
        self::assertNull($rule->validate(null, []));
    }

    public function testStringRuleRejectsNonString(): void
    {
        $rule = new StringRule();
        self::assertNotNull($rule->validate(42, []));
        self::assertNotNull($rule->validate(3.14, []));
        self::assertNotNull($rule->validate(true, []));
        self::assertNotNull($rule->validate([], []));
    }

    public function testIntegerRuleAcceptsInt(): void
    {
        $rule = new IntegerRule();
        self::assertNull($rule->validate(42, []));
        self::assertNull($rule->validate(0, []));
        self::assertNull($rule->validate(-5, []));
    }

    public function testIntegerRuleSkipsNull(): void
    {
        $rule = new IntegerRule();
        self::assertNull($rule->validate(null, []));
    }

    public function testIntegerRuleRejectsNonInt(): void
    {
        $rule = new IntegerRule();
        self::assertNotNull($rule->validate('42', []));
        self::assertNotNull($rule->validate(42.0, []));
        self::assertNotNull($rule->validate(true, []));
    }

    public function testFloatRuleAcceptsFloat(): void
    {
        $rule = new FloatRule();
        self::assertNull($rule->validate(3.14, []));
        self::assertNull($rule->validate(0.0, []));
        self::assertNull($rule->validate(-1.5, []));
    }

    public function testFloatRuleSkipsNull(): void
    {
        $rule = new FloatRule();
        self::assertNull($rule->validate(null, []));
    }

    public function testFloatRuleRejectsNonFloat(): void
    {
        $rule = new FloatRule();
        self::assertNotNull($rule->validate(42, []));
        self::assertNotNull($rule->validate('3.14', []));
    }

    public function testBooleanRuleAcceptsBool(): void
    {
        $rule = new BooleanRule();
        self::assertNull($rule->validate(true, []));
        self::assertNull($rule->validate(false, []));
    }

    public function testBooleanRuleSkipsNull(): void
    {
        $rule = new BooleanRule();
        self::assertNull($rule->validate(null, []));
    }

    public function testBooleanRuleRejectsNonBool(): void
    {
        $rule = new BooleanRule();
        self::assertNotNull($rule->validate(0, []));
        self::assertNotNull($rule->validate(1, []));
        self::assertNotNull($rule->validate('true', []));
    }

    public function testArrayRuleAcceptsArray(): void
    {
        $rule = new ArrayRule();
        self::assertNull($rule->validate([], []));
        self::assertNull($rule->validate(['x', 'y'], []));
    }

    public function testArrayRuleSkipsNull(): void
    {
        $rule = new ArrayRule();
        self::assertNull($rule->validate(null, []));
    }

    public function testArrayRuleRejectsNonArray(): void
    {
        $rule = new ArrayRule();
        self::assertNotNull($rule->validate('hello', []));
        self::assertNotNull($rule->validate(42, []));
    }
}
