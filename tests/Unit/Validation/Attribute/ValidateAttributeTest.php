<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Validation\Attribute;

use Framework\Validation\Attribute\Validate;
use Framework\Validation\Rule\MinRule;
use Framework\Validation\Rule\RequiredRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Validate::class)]
final class ValidateAttributeTest extends TestCase
{
    public function testConstructWithStringShorthand(): void
    {
        $attr = new Validate('required|email|min:3');
        self::assertSame('required|email|min:3', $attr->rules);
    }

    public function testConstructWithArrayOfStrings(): void
    {
        $attr = new Validate(['required', 'email', 'min:3']);
        self::assertSame(['required', 'email', 'min:3'], $attr->rules);
    }

    public function testConstructWithArrayOfRuleInstances(): void
    {
        $rules = [new RequiredRule(), new MinRule(min: 5)];
        $attr = new Validate($rules);
        self::assertSame($rules, $attr->rules);
    }

    public function testConstructWithMixed(): void
    {
        $rules = ['required', new MinRule(min: 5), 'email'];
        $attr = new Validate($rules);
        self::assertSame($rules, $attr->rules);
    }

    public function testConstructWithEmptyArray(): void
    {
        $attr = new Validate([]);
        self::assertSame([], $attr->rules);
    }

    public function testIsRepeatableAttribute(): void
    {
        $reflection = new \ReflectionClass(Validate::class);
        $attributes = $reflection->getAttributes(\Attribute::class);
        self::assertCount(1, $attributes);
        $args = $attributes[0]->getArguments();
        self::assertSame(
            \Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER | \Attribute::IS_REPEATABLE,
            $args[0],
        );
    }
}
