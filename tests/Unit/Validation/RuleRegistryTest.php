<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Validation;

use Framework\Container\NotFoundException;
use Framework\Validation\Rule\EmailRule;
use Framework\Validation\Rule\InRule;
use Framework\Validation\Rule\MinRule;
use Framework\Validation\Rule\RegexRule;
use Framework\Validation\Rule\RequiredRule;
use Framework\Validation\Rule\RuleInterface;
use Framework\Validation\Rule\RuleRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleRegistry::class)]
final class RuleRegistryTest extends TestCase
{
    public function testDefaultRegistryHasAllBuiltins(): void
    {
        $registry = new RuleRegistry();

        $expected = [
            'required', 'string', 'integer', 'float', 'boolean', 'array',
            'email', 'url', 'uuid', 'regex', 'min', 'max', 'length', 'between', 'in',
        ];
        self::assertSame($expected, $registry->names());
    }

    public function testRegistryHasBuiltinRule(): void
    {
        $registry = new RuleRegistry();
        self::assertTrue($registry->has('required'));
        self::assertTrue($registry->has('email'));
        self::assertInstanceOf(RequiredRule::class, $registry->get('required'));
        self::assertInstanceOf(EmailRule::class, $registry->get('email'));
    }

    public function testRegistryReturnsFalseForUnknownRule(): void
    {
        $registry = new RuleRegistry();
        self::assertFalse($registry->has('non_existent'));
    }

    public function testGetThrowsOnUnknownRule(): void
    {
        $registry = new RuleRegistry();
        $this->expectException(NotFoundException::class);
        $registry->get('non_existent');
    }

    public function testRegisterCustomRule(): void
    {
        $registry = new RuleRegistry();
        $rule = new class implements RuleInterface {
            public function validate(mixed $value, array $params): ?string
            {
                return $value === 'ok' ? null : 'must be ok';
            }
            public function name(): string
            {
                return 'ok';
            }
            public function params(): array
            {
                return [];
            }
        };

        $registry->register('ok', $rule);
        self::assertSame($rule, $registry->get('ok'));
    }

    public function testRegisterOverwritesExistingRule(): void
    {
        $registry = new RuleRegistry();
        $custom = new InRule(values: ['x']);
        $registry->register('in', $custom);
        self::assertSame($custom, $registry->get('in'));
    }

    public function testAllReturnsInsertionOrder(): void
    {
        $registry = new RuleRegistry();
        self::assertSame(
            ['required', 'string', 'integer', 'float', 'boolean', 'array', 'email', 'url', 'uuid', 'regex', 'min', 'max', 'length', 'between', 'in'],
            array_keys($registry->all()),
        );
    }

    public function testDefaultsConstructorAllowsCustomSubset(): void
    {
        $registry = new RuleRegistry(['only_this' => new RequiredRule()]);
        self::assertSame(['only_this'], $registry->names());
        self::assertFalse($registry->has('email'));
    }

    public function testCustomRuleWithParams(): void
    {
        $registry = new RuleRegistry();
        $registry->register('min', new MinRule(min: 5));
        self::assertSame(['min' => 5], $registry->get('min')->params());
    }
}
