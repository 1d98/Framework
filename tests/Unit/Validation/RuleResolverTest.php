<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Validation;

use Framework\Container\NotFoundException;
use Framework\Validation\Rule\BetweenRule;
use Framework\Validation\Rule\EmailRule;
use Framework\Validation\Rule\InRule;
use Framework\Validation\Rule\LengthRule;
use Framework\Validation\Rule\MaxRule;
use Framework\Validation\Rule\MinRule;
use Framework\Validation\Rule\RegexRule;
use Framework\Validation\Rule\RequiredRule;
use Framework\Validation\Rule\RuleInterface;
use Framework\Validation\Rule\RuleRegistry;
use Framework\Validation\RuleResolver;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleResolver::class)]
final class RuleResolverTest extends TestCase
{
    private RuleResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new RuleResolver(new RuleRegistry());
    }

    public function testResolveReturnsExactRegisteredRule(): void
    {
        $rule = $this->resolver->resolve('required');
        self::assertInstanceOf(RequiredRule::class, $rule);
    }

    public function testResolveIsCaseInsensitive(): void
    {
        self::assertInstanceOf(EmailRule::class, $this->resolver->resolve('email'));
        self::assertInstanceOf(EmailRule::class, $this->resolver->resolve('EMAIL'));
        self::assertInstanceOf(EmailRule::class, $this->resolver->resolve('Email'));
    }

    public function testResolveStripsFqcnToShortName(): void
    {
        self::assertInstanceOf(EmailRule::class, $this->resolver->resolve('Framework\\Validation\\Rule\\EmailRule'));
    }

    public function testResolveThrowsOnUnknownRule(): void
    {
        $this->expectException(NotFoundException::class);
        $this->resolver->resolve('does_not_exist');
    }

    public function testResolveThrowsOnInvalidSyntax(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->resolver->resolve('1invalid');
    }

    public function testParseRuleListSplitsOnPipe(): void
    {
        $rules = $this->resolver->parseRuleList('required|email');
        self::assertCount(2, $rules);
        self::assertInstanceOf(RequiredRule::class, $rules[0]);
        self::assertInstanceOf(EmailRule::class, $rules[1]);
    }

    public function testParseRuleListIgnoresEmptyTokens(): void
    {
        $rules = $this->resolver->parseRuleList('required||email|');
        self::assertCount(2, $rules);
    }

    public function testParseRuleListTrimsWhitespace(): void
    {
        $rules = $this->resolver->parseRuleList('  required | email ');
        self::assertCount(2, $rules);
    }

    public function testParseRuleListWithEmptyStringReturnsEmpty(): void
    {
        self::assertSame([], $this->resolver->parseRuleList(''));
    }

    public function testParseRuleSpecsHandlesString(): void
    {
        $rules = $this->resolver->parseRuleSpecs('required|email');
        self::assertCount(2, $rules);
    }

    public function testParseRuleSpecsHandlesNull(): void
    {
        self::assertSame([], $this->resolver->parseRuleSpecs(null));
    }

    public function testParseRuleSpecsHandlesListOfStrings(): void
    {
        $rules = $this->resolver->parseRuleSpecs(['required', 'email']);
        self::assertCount(2, $rules);
        self::assertInstanceOf(RequiredRule::class, $rules[0]);
    }

    public function testParseRuleSpecsPreservesRuleInterfaceInstances(): void
    {
        $custom = new MinRule(min: 5);
        $rules = $this->resolver->parseRuleSpecs(['required', $custom]);
        self::assertCount(2, $rules);
        self::assertSame($custom, $rules[1]);
    }

    public function testParseRuleSpecsSkipsEmptyStringsInList(): void
    {
        $rules = $this->resolver->parseRuleSpecs(['required', '', '  ', 'email']);
        self::assertCount(2, $rules);
    }

    public function testBuildParametricRuleForMin(): void
    {
        $rule = $this->resolver->buildParametricRule('min', ['8']);
        self::assertInstanceOf(MinRule::class, $rule);
        self::assertSame(['min' => 8], $rule->params());
    }

    public function testBuildParametricRuleForMax(): void
    {
        $rule = $this->resolver->buildParametricRule('max', ['255']);
        self::assertInstanceOf(MaxRule::class, $rule);
        self::assertSame(['max' => 255], $rule->params());
    }

    public function testBuildParametricRuleForBetween(): void
    {
        $rule = $this->resolver->buildParametricRule('between', ['1', '10']);
        self::assertInstanceOf(BetweenRule::class, $rule);
        self::assertSame(['min' => 1, 'max' => 10], $rule->params());
    }

    public function testBuildParametricRuleForRegex(): void
    {
        $rule = $this->resolver->buildParametricRule('regex', ['/^a.*/']);
        self::assertInstanceOf(RegexRule::class, $rule);
        self::assertSame(['pattern' => '/^a.*/'], $rule->params());
    }

    public function testBuildParametricRuleForIn(): void
    {
        $rule = $this->resolver->buildParametricRule('in', ['a', 'b', 'c']);
        self::assertInstanceOf(InRule::class, $rule);
        self::assertSame(['values' => ['a', 'b', 'c']], $rule->params());
    }

    public function testBuildParametricRuleForLength(): void
    {
        $rule = $this->resolver->buildParametricRule('length', ['5']);
        self::assertInstanceOf(LengthRule::class, $rule);
        self::assertSame(['min' => 5, 'max' => 5], $rule->params());
    }

    public function testBuildParametricRuleForLengthWithMinMax(): void
    {
        $rule = $this->resolver->buildParametricRule('length', ['min=3', 'max=10']);
        self::assertInstanceOf(LengthRule::class, $rule);
        self::assertSame(['min' => 3, 'max' => 10], $rule->params());
    }

    public function testBuildParametricRuleForUnknownFallsBackToRegistry(): void
    {
        $rule = $this->resolver->buildParametricRule('email', []);
        self::assertInstanceOf(EmailRule::class, $rule);
    }

    public function testParseRuleListBuildsCompletePipeline(): void
    {
        $rules = $this->resolver->parseRuleList('required|email|min:8|max:255');
        self::assertCount(4, $rules);
        self::assertInstanceOf(RequiredRule::class, $rules[0]);
        self::assertInstanceOf(EmailRule::class, $rules[1]);
        self::assertInstanceOf(MinRule::class, $rules[2]);
        self::assertInstanceOf(MaxRule::class, $rules[3]);
    }

    public function testParseRuleListWithBetween(): void
    {
        $rules = $this->resolver->parseRuleList('between:1,10');
        self::assertCount(1, $rules);
        self::assertInstanceOf(BetweenRule::class, $rules[0]);
    }

    public function testRegisterAddsParametricRuleViaExtensionPoint(): void
    {
        $this->resolver->register('date_format', static function (array $params): RuleInterface {
            return new DateFormatRuleStub($params[0] ?? '');
        });

        $rules = $this->resolver->parseRuleList('date_format:Y-m-d');
        self::assertCount(1, $rules);
        self::assertInstanceOf(DateFormatRuleStub::class, $rules[0]);
        self::assertSame('Y-m-d', $rules[0]->params()['format']);
    }

    public function testRegisterResolvesViaBuildParametricRule(): void
    {
        $this->resolver->register('date_format', static function (array $params): RuleInterface {
            return new DateFormatRuleStub($params[0] ?? '');
        });

        $rule = $this->resolver->buildParametricRule('date_format', ['Y-m-d']);
        self::assertInstanceOf(DateFormatRuleStub::class, $rule);
    }

    public function testRegisterIsCaseInsensitive(): void
    {
        $this->resolver->register('date_format', static function (array $params): RuleInterface {
            return new DateFormatRuleStub($params[0] ?? '');
        });

        $rules = $this->resolver->parseRuleList('DATE_FORMAT:Y-m-d');
        self::assertCount(1, $rules);
        self::assertInstanceOf(DateFormatRuleStub::class, $rules[0]);
    }
}

final class DateFormatRuleStub implements RuleInterface
{
    public function __construct(private string $format)
    {
    }

    public function validate(mixed $value, array $params): ?string
    {
        $formatParam = $params['format'] ?? $this->format;
        $format = is_string($formatParam) ? $formatParam : '';
        if (!is_string($value) || $format === '') {
            return 'DateFormatRule requires string value and format';
        }
        $dt = \DateTime::createFromFormat('!' . $format, $value);
        return $dt instanceof \DateTime ? null : 'Field is not a valid date';
    }

    public function name(): string
    {
        return 'date_format';
    }

    public function params(): array
    {
        return ['format' => $this->format];
    }
}
