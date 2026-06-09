<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Validation\Rule;

use Framework\Validation\Rule\RequiredRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequiredRule::class)]
final class RequiredRuleTest extends TestCase
{
    private RequiredRule $rule;

    protected function setUp(): void
    {
        $this->rule = new RequiredRule();
    }

    public function testRejectsNull(): void
    {
        self::assertNotNull($this->rule->validate(null, []));
    }

    public function testRejectsEmptyString(): void
    {
        self::assertNotNull($this->rule->validate('', []));
    }

    public function testRejectsEmptyArray(): void
    {
        self::assertNotNull($this->rule->validate([], []));
    }

    public function testAcceptsZeroInt(): void
    {
        self::assertNull($this->rule->validate(0, []));
    }

    public function testAcceptsZeroFloat(): void
    {
        self::assertNull($this->rule->validate(0.0, []));
    }

    public function testAcceptsFalseBool(): void
    {
        self::assertNull($this->rule->validate(false, []));
    }

    public function testAcceptsZeroString(): void
    {
        self::assertNull($this->rule->validate('0', []));
    }

    public function testAcceptsNonEmptyString(): void
    {
        self::assertNull($this->rule->validate('hello', []));
    }

    public function testAcceptsNonEmptyArray(): void
    {
        self::assertNull($this->rule->validate(['x'], []));
    }

    public function testNameIsRequired(): void
    {
        self::assertSame('required', $this->rule->name());
    }

    public function testParamsIsEmpty(): void
    {
        self::assertSame([], $this->rule->params());
    }
}
