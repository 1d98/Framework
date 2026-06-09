<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Validation;

use Framework\Validation\Attribute\From;
use Framework\Validation\Attribute\Validate;
use Framework\Validation\Rule\RuleRegistry;
use Framework\Validation\ValidationException;
use Framework\Validation\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Validator::class)]
#[CoversClass(From::class)]
final class ConstructorHydrationTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator(new RuleRegistry());
        Validator::clearCaches();
    }

    public function testNonTrivialConstructorIsInvoked(): void
    {
        $dto = $this->validator->validate(NormalizingEmailDto::class, [
            'email' => 'Alice@Example.COM',
        ]);

        self::assertInstanceOf(NormalizingEmailDto::class, $dto);
        self::assertSame('alice@example.com', $dto->email);
    }

    public function testConstructorArgsMatchedByName(): void
    {
        $dto = $this->validator->validate(SignupDto::class, [
            'email' => 'a@b.com',
            'password' => 'secret',
        ]);

        self::assertSame('a@b.com', $dto->email);
        self::assertSame('secret', $dto->password);
    }

    public function testCaseInsensitiveParamMatching(): void
    {
        $dto = $this->validator->validate(CaseInsensitiveDto::class, [
            'Email' => 'x@y.com',
            'Password' => 'pw',
        ]);

        self::assertSame('x@y.com', $dto->email);
        self::assertSame('pw', $dto->password);
    }

    public function testRequiredConstructorArgMissingThrows(): void
    {
        try {
            $this->validator->validate(RequiredCtorDto::class, []);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors()->all();
            self::assertCount(1, $errors);
            self::assertSame('email', $errors[0]->property);
            self::assertStringContainsString("missing required property 'email'", $errors[0]->message);
        }
    }

    public function testMissingOptionalArgFallsBackToDefault(): void
    {
        $dto = $this->validator->validate(DefaultsDto::class, ['email' => 'a@b.com']);

        self::assertSame('a@b.com', $dto->email);
        self::assertSame(18, $dto->age);
    }

    public function testDtoWithoutConstructorStillHydratesViaPropertySet(): void
    {
        $dto = $this->validator->validate(LegacyNoCtorDto::class, ['name' => 'Alice']);

        self::assertSame('Alice', $dto->name);
    }

    public function testDtoWithZeroArgConstructorStillHydratesViaPropertySet(): void
    {
        $dto = $this->validator->validate(ZeroArgCtorDto::class, ['name' => 'Bob']);

        self::assertSame('Bob', $dto->name);
    }

    public function testNestedDtoViaConstructorArg(): void
    {
        $dto = $this->validator->validate(ProfileDto::class, [
            'name' => 'Alice',
            'address' => ['email' => 'addr@example.com'],
        ]);

        self::assertInstanceOf(ProfileDto::class, $dto);
        self::assertInstanceOf(AddressCtorDto::class, $dto->address);
        self::assertSame('addr@example.com', $dto->address->email);
    }

    public function testArrayOfDtosViaConstructorArg(): void
    {
        $dto = $this->validator->validate(CartDto::class, [
            'items' => [
                ['sku' => 'A'],
                ['sku' => 'B'],
            ],
        ]);

        self::assertNotNull($dto->items);
        $items = $dto->items;
        self::assertCount(2, $items);
        self::assertInstanceOf(ItemDto::class, $items[0]);
        self::assertSame('A', $items[0]->sku);
        self::assertSame('B', $items[1]->sku);
    }

    public function testFromAttributeMapsDottedPath(): void
    {
        $dto = $this->validator->validate(AliasedDto::class, [
            'user' => ['email' => 'aliased@example.com'],
        ]);

        self::assertSame('aliased@example.com', $dto->email);
    }

    public function testFromAttributeMapsSimpleKey(): void
    {
        $dto = $this->validator->validate(SimpleAliasedDto::class, [
            'payload' => 'hello',
        ]);

        self::assertSame('hello', $dto->body);
    }

    public function testFromAttributeRequiredPathMissingThrows(): void
    {
        try {
            $this->validator->validate(AliasedDto::class, []);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors()->all();
            self::assertCount(1, $errors);
            self::assertStringContainsString("missing required property 'user.email'", $errors[0]->message);
        }
    }

    public function testFromAttributeTraversalIsCaseInsensitiveOnOuterSegment(): void
    {
        $dto = $this->validator->validate(AliasedDto::class, [
            'User' => ['email' => 'outer-mismatch@example.com'],
        ]);

        self::assertSame('outer-mismatch@example.com', $dto->email);
    }

    public function testFromAttributeTraversalIsCaseInsensitiveOnInnerSegment(): void
    {
        $dto = $this->validator->validate(AliasedDto::class, [
            'user' => ['Email' => 'inner-mismatch@example.com'],
        ]);

        self::assertSame('inner-mismatch@example.com', $dto->email);
    }

    public function testFromAttributeTraversalIsCaseInsensitiveOnBothSegments(): void
    {
        $dto = $this->validator->validate(AliasedDto::class, [
            'User' => ['Email' => 'both-mismatch@example.com'],
        ]);

        self::assertSame('both-mismatch@example.com', $dto->email);
    }

    public function testFromAttributeTraversalMatchesExactCase(): void
    {
        $dto = $this->validator->validate(AliasedDto::class, [
            'user' => ['email' => 'exact@example.com'],
        ]);

        self::assertSame('exact@example.com', $dto->email);
    }

    public function testFromAttributeDoesNotFallBackToParameterName(): void
    {
        try {
            $this->validator->validate(AliasedDto::class, [
                'role' => 'admin',
            ]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors()->all();
            self::assertCount(1, $errors);
            self::assertSame('user.email', $errors[0]->property);
            self::assertStringContainsString("missing required property 'user.email'", $errors[0]->message);
        }
    }

    public function testNestedDtosInheritCtorHydration(): void
    {
        $dto = $this->validator->validate(OuterNormalizingDto::class, [
            'inner' => ['email' => 'Mixed@Case.IO'],
        ]);

        self::assertInstanceOf(InnerNormalizingDto::class, $dto->inner);
        self::assertSame('mixed@case.io', $dto->inner->email);
    }

    public function testArrayOfDtosItemsInheritCtorHydration(): void
    {
        $dto = $this->validator->validate(OuterItemsNormalizingDto::class, [
            'items' => [
                ['email' => 'A@X.COM'],
                ['email' => 'B@Y.COM'],
            ],
        ]);

        $items = $dto->items;
        self::assertNotNull($items);
        self::assertCount(2, $items);
        self::assertSame('a@x.com', $items[0]->email);
        self::assertSame('b@y.com', $items[1]->email);
    }
}

final class NormalizingEmailDto
{
    public function __construct(
        #[Validate('required|string')]
        public string $email,
    ) {
        $this->email = strtolower($email);
    }
}

final class SignupDto
{
    public function __construct(
        #[Validate('required|string')]
        public string $email,
        #[Validate('required|string|min:1')]
        public string $password,
    ) {
    }
}

final class CaseInsensitiveDto
{
    public function __construct(
        public string $email,
        public string $password,
    ) {
    }
}

final class RequiredCtorDto
{
    public function __construct(
        public string $email,
    ) {
    }
}

final class DefaultsDto
{
    public function __construct(
        #[Validate('required|string')]
        public string $email,
        public int $age = 18,
    ) {
    }
}

final class LegacyNoCtorDto
{
    public ?string $name = null;
}

final class ZeroArgCtorDto
{
    public ?string $name = null;

    public function __construct()
    {
    }
}

final class AddressCtorDto
{
    public function __construct(
        #[Validate('required|string')]
        public ?string $email = null,
    ) {
    }
}

final class ProfileDto
{
    public function __construct(
        #[Validate('required|string')]
        public string $name,
        #[Validate(AddressCtorDto::class)]
        public ?AddressCtorDto $address = null,
    ) {
    }
}

final class ItemDto
{
    public function __construct(
        #[Validate('required|string')]
        public ?string $sku = null,
    ) {
    }
}

final class CartDto
{
    /**
     * @param list<ItemDto>|null $items
     */
    public function __construct(
        #[Validate(items: ItemDto::class)]
        public ?array $items = null,
    ) {
    }
}

final class AliasedDto
{
    public function __construct(
        #[From('user.email')]
        public string $email,
    ) {
    }
}

final class SimpleAliasedDto
{
    public function __construct(
        #[From('payload')]
        public string $body,
    ) {
    }
}

final class InnerNormalizingDto
{
    public function __construct(
        public string $email,
    ) {
        $this->email = strtolower($email);
    }
}

final class OuterNormalizingDto
{
    public function __construct(
        #[Validate(InnerNormalizingDto::class)]
        public ?InnerNormalizingDto $inner = null,
    ) {
    }
}

final class OuterItemsNormalizingDto
{
    /**
     * @param list<InnerNormalizingDto>|null $items
     */
    public function __construct(
        #[Validate(items: InnerNormalizingDto::class)]
        public ?array $items = null,
    ) {
    }
}
