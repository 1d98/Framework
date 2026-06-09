<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Validation;

use Framework\Validation\Attribute\Validate;
use Framework\Validation\Rule\MaxRule;
use Framework\Validation\Rule\MinRule;
use Framework\Validation\Rule\RuleRegistry;
use Framework\Validation\ValidationErrorCollection;
use Framework\Validation\ValidationException;
use Framework\Validation\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Validator::class)]
#[CoversClass(Validate::class)]
final class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator(new RuleRegistry());
    }

    public function testValidDataReturnsDto(): void
    {
        $dto = $this->validator->validate(SimpleDto::class, ['name' => 'Alice', 'age' => 30]);
        self::assertInstanceOf(SimpleDto::class, $dto);
        self::assertSame('Alice', $dto->name);
        self::assertSame(30, $dto->age);
    }

    public function testInvalidDataThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(SimpleDto::class, []);
    }

    public function testCollectsAllErrorsNotFailFast(): void
    {
        try {
            $this->validator->validate(SimpleDto::class, []);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            self::assertGreaterThanOrEqual(2, $errors->count());
            self::assertCount(1, $errors->forProperty('name'));
            self::assertCount(1, $errors->forProperty('age'));
            return;
        }
        self::fail('Expected ValidationException');
    }

    public function testCheckReturnsCollectionWithoutThrowing(): void
    {
        $errors = $this->validator->check(SimpleDto::class, []);
        self::assertFalse($errors->isEmpty());
        self::assertInstanceOf(ValidationErrorCollection::class, $errors);
    }

    public function testCheckReturnsEmptyForValid(): void
    {
        $errors = $this->validator->check(SimpleDto::class, ['name' => 'Alice', 'age' => 30]);
        self::assertTrue($errors->isEmpty());
    }

    public function testNoValidateAttributeSkipsProperty(): void
    {
        $dto = $this->validator->validate(UnvalidatedDto::class, ['unvalidated' => 'anything']);
        self::assertSame('anything', $dto->unvalidated);
    }

    public function testPrivatePropertyIsSetViaReflection(): void
    {
        $dto = $this->validator->validate(PrivateDto::class, ['name' => 'Alice']);
        self::assertSame('Alice', $dto->getName());
    }

    public function testStringShorthandRequiredEmailMinMax(): void
    {
        $emailDto = $this->validator->validate(StringShorthandDto::class, [
            'email' => 'alice@example.com',
        ]);
        self::assertSame('alice@example.com', $emailDto->email);

        $ageDto = $this->validator->validate(StringShorthandAgeDto::class, [
            'age' => 30,
        ]);
        self::assertSame(30, $ageDto->age);
    }

    public function testStringShorthandFailsWithInvalidEmail(): void
    {
        try {
            $this->validator->validate(StringShorthandDto::class, [
                'email' => 'not-an-email',
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors()->forProperty('email');
            self::assertCount(1, $errors);
            self::assertSame('email', $errors[0]->rule);
            return;
        }
        self::fail('Expected ValidationException');
    }

    public function testStringShorthandFailsWithMinAge(): void
    {
        try {
            $this->validator->validate(StringShorthandAgeDto::class, [
                'age' => 5,
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors()->forProperty('age');
            self::assertCount(1, $errors);
            self::assertSame('min', $errors[0]->rule);
            return;
        }
        self::fail('Expected ValidationException');
    }

    public function testStringShorthandWithoutPipeSplitsNoParam(): void
    {
        $dto = $this->validator->validate(OnlyRequiredDto::class, ['name' => 'Bob']);
        self::assertSame('Bob', $dto->name);
    }

    public function testCustomRuleInstanceOverridesRegistry(): void
    {
        $dto = $this->validator->validate(MixedRulesDto::class, [
            'name' => 'Alice',
            'score' => 10,
        ]);
        self::assertSame('Alice', $dto->name);
        self::assertSame(10, $dto->score);
    }

    public function testUnknownRuleInStringThrows(): void
    {
        $this->expectException(\Framework\Container\NotFoundException::class);
        $this->validator->validate(UnknownRuleDto::class, ['name' => 'x']);
    }

    public function testMultipleValidateAttributesAreMerged(): void
    {
        $dto = $this->validator->validate(MultiAttrDto::class, ['name' => 'Alice', 'age' => 30]);
        self::assertSame('Alice', $dto->name);
        self::assertSame(30, $dto->age);
    }

    public function testMissingPropertyBecomesNullViaReflection(): void
    {
        $dto = $this->validator->validate(PartialDto::class, ['name' => 'Alice']);
        self::assertSame('Alice', $dto->name);
        self::assertNull($dto->age);
    }

    public function testExtraDataKeysAreIgnored(): void
    {
        $dto = $this->validator->validate(SimpleDto::class, [
            'name' => 'Alice',
            'age' => 30,
            'unknown' => 'whatever',
        ]);
        self::assertSame('Alice', $dto->name);
    }
}

final class SimpleDto
{
    public function __construct(
        #[Validate(['required', 'string'])]
        public ?string $name = null,
        #[Validate(['required', 'integer', 'min:0', 'max:150'])]
        public ?int $age = null,
    ) {
    }
}

final class PartialDto
{
    public function __construct(
        #[Validate(['required', 'string'])]
        public ?string $name = null,
        #[Validate(['integer', 'min:0', 'max:150'])]
        public ?int $age = null,
    ) {
    }
}

final class UnvalidatedDto
{
    public function __construct(public mixed $unvalidated = null)
    {
    }
}

final class PrivateDto
{
    private mixed $name = null;

    public function getName(): mixed
    {
        return $this->name;
    }
}

final class StringShorthandDto
{
    public function __construct(
        #[Validate('required|email')]
        public ?string $email = null,
    ) {
    }
}

final class StringShorthandAgeDto
{
    public function __construct(
        #[Validate('required|integer|min:18|max:120')]
        public ?int $age = null,
    ) {
    }
}

final class OnlyRequiredDto
{
    public function __construct(
        #[Validate('required')]
        public ?string $name = null,
    ) {
    }
}

final class MixedRulesDto
{
    public function __construct(
        #[Validate(['required', 'string'])]
        public ?string $name = null,
        #[Validate([new MinRule(min: 0), new MaxRule(max: 100)])]
        public ?int $score = null,
    ) {
    }
}

final class UnknownRuleDto
{
    public function __construct(
        #[Validate('required|nonexistent_rule')]
        public ?string $name = null,
    ) {
    }
}

final class MultiAttrDto
{
    public function __construct(
        #[Validate(['required', 'string']), Validate(['min:3'])]
        public ?string $name = null,
        #[Validate(['required']), Validate(['integer'])]
        public ?int $age = null,
    ) {
    }
}
