<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Validation;

use Framework\Validation\Attribute\Validate;
use Framework\Validation\Rule\RuleRegistry;
use Framework\Validation\ValidationError;
use Framework\Validation\ValidationException;
use Framework\Validation\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Validator::class)]
#[CoversClass(Validate::class)]
#[CoversClass(ValidationError::class)]
final class NestedValidationTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator(new RuleRegistry());
    }

    public function testNestedDtoInvalidEmailProducesNestedPointer(): void
    {
        try {
            $this->validator->validate(CreateOrderRequest::class, [
                'email' => 'ok@example.com',
                'address' => ['email' => 'not-an-email'],
            ]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors()->all();
            self::assertCount(1, $errors);
            self::assertSame('email', $errors[0]->property);
            self::assertSame('email', $errors[0]->rule);
            self::assertSame(['address'], $errors[0]->path);
            self::assertSame('/address/email', $errors[0]->pointer());
        }
    }

    public function testNestedDtoBuildsNestedObjectGraph(): void
    {
        $dto = $this->validator->validate(CreateOrderRequest::class, [
            'email' => 'ok@example.com',
            'address' => ['email' => 'addr@example.com'],
        ]);
        self::assertInstanceOf(CreateOrderRequest::class, $dto);
        self::assertInstanceOf(Address::class, $dto->address);
        self::assertSame('addr@example.com', $dto->address->email);
    }

    public function testTopLevelErrorHasEmptyPathAndSlashPointer(): void
    {
        try {
            $this->validator->validate(CreateOrderRequest::class, [
                'email' => 'not-an-email',
            ]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors()->all();
            self::assertGreaterThanOrEqual(1, count($errors));
            $emailErr = null;
            foreach ($errors as $err) {
                if ($err->property === 'email' && $err->rule === 'email') {
                    $emailErr = $err;
                    break;
                }
            }
            self::assertNotNull($emailErr);
            self::assertSame([], $emailErr->path);
            self::assertSame('/email', $emailErr->pointer());
        }
    }

    public function testNullableNestedDtoWithNullValuePasses(): void
    {
        $dto = $this->validator->validate(CreateOrderRequest::class, [
            'email' => 'ok@example.com',
            'address' => null,
        ]);
        self::assertNull($dto->address);
    }

    public function testArrayOfDtosItemErrorProducesIndexedPointer(): void
    {
        try {
            $this->validator->validate(OrderWithItems::class, [
                'sku' => 'PARENT',
                'items' => [
                    ['sku' => 'A'],
                    ['sku' => ''],
                    ['sku' => 'C'],
                ],
            ]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors()->all();
            $itemErr = null;
            foreach ($errors as $err) {
                if ($err->property === 'sku' && in_array('1', $err->path, true)) {
                    $itemErr = $err;
                    break;
                }
            }
            self::assertNotNull($itemErr, 'Expected error on items[1].sku');
            self::assertSame(['items', '1'], $itemErr->path);
            self::assertSame('/items/1/sku', $itemErr->pointer());
        }
    }

    public function testArrayOfDtosBuildsNestedObjectList(): void
    {
        $dto = $this->validator->validate(OrderWithItems::class, [
            'sku' => 'PARENT',
            'items' => [
                ['sku' => 'A'],
                ['sku' => 'B'],
            ],
        ]);
        self::assertNotNull($dto->items);
        self::assertCount(2, $dto->items);
        self::assertInstanceOf(OrderItem::class, $dto->items[0]);
        self::assertSame('A', $dto->items[0]->sku);
        self::assertSame('B', $dto->items[1]->sku);
    }

    public function testArrayOfDtosWithNonArrayValueEmitsTypeErrorAtCorrectPath(): void
    {
        try {
            $this->validator->validate(OrderWithItems::class, [
                'sku' => 'PARENT',
                'items' => 'not-an-array',
            ]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors()->all();
            $typeErr = null;
            foreach ($errors as $err) {
                if ($err->rule === 'type' && $err->path === ['items']) {
                    $typeErr = $err;
                    break;
                }
            }
            self::assertNotNull($typeErr);
            self::assertSame('', $typeErr->property);
            self::assertSame(['items'], $typeErr->path);
            self::assertSame('/items', $typeErr->pointer());
        }
    }

    public function testArrayOfDtosWithAssociativeArrayEmitsListTypeError(): void
    {
        try {
            $this->validator->validate(OrderWithItems::class, [
                'sku' => 'PARENT',
                'items' => ['foo' => 'bar'],
            ]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors()->all();
            $typeErr = null;
            foreach ($errors as $err) {
                if ($err->rule === 'type' && $err->path === ['items']) {
                    $typeErr = $err;
                    break;
                }
            }
            self::assertNotNull($typeErr);
            self::assertSame('/items', $typeErr->pointer());
        }
    }

    public function testExistingSimpleClassStringRuleOnStringPropertyStillResolves(): void
    {
        $dto = $this->validator->validate(SimpleClassStringRuleDto::class, [
            'email' => 'ok@example.com',
        ]);
        self::assertSame('ok@example.com', $dto->email);

        try {
            $this->validator->validate(SimpleClassStringRuleDto::class, [
                'email' => 'not-an-email',
            ]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors()->all();
            self::assertCount(1, $errors);
            self::assertSame('email', $errors[0]->property);
            self::assertSame('email', $errors[0]->rule);
            self::assertSame([], $errors[0]->path);
            self::assertSame('/email', $errors[0]->pointer());
        }
    }

    public function testExistingStringShorthandRulesOnPrimitivesAreUnchanged(): void
    {
        $dto = $this->validator->validate(SimpleShorthandDto::class, [
            'name' => 'Alice',
            'age' => 30,
        ]);
        self::assertSame('Alice', $dto->name);
        self::assertSame(30, $dto->age);
    }

    public function testBindBuildsNestedGraphThroughRequestBinding(): void
    {
        $dto = $this->validator->validate(CreateOrderRequest::class, [
            'email' => 'ok@example.com',
            'address' => [
                'email' => 'nested@example.com',
            ],
        ]);
        self::assertInstanceOf(Address::class, $dto->address);
        self::assertSame('nested@example.com', $dto->address->email);
    }

    public function testDeeplyNestedErrorCarriesFullPath(): void
    {
        try {
            $this->validator->validate(OuterDto::class, [
                'middle' => [
                    'inner' => ['email' => 'not-an-email'],
                ],
            ]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors()->all();
            self::assertCount(1, $errors);
            self::assertSame(['middle', 'inner'], $errors[0]->path);
            self::assertSame('/middle/inner/email', $errors[0]->pointer());
        }
    }

    public function testReadonlyNestedDtoIsBuiltViaReflection(): void
    {
        $dto = $this->validator->validate(ReadonlyParentDto::class, [
            'label' => 'parent',
            'address' => ['email' => 'r@example.com'],
        ]);
        self::assertInstanceOf(ReadonlyParentDto::class, $dto);
        self::assertInstanceOf(ReadonlyAddressDto::class, $dto->address);
        self::assertSame('r@example.com', $dto->address->email);
    }

    public function testReadonlyArrayOfDtosIsBuiltViaReflection(): void
    {
        $dto = $this->validator->validate(ReadonlyItemsParentDto::class, [
            'sku' => 'P',
            'items' => [['sku' => 'A'], ['sku' => 'B']],
        ]);
        self::assertNotNull($dto->items);
        self::assertCount(2, $dto->items);
        self::assertInstanceOf(ReadonlyItemDto::class, $dto->items[0]);
        self::assertSame('A', $dto->items[0]->sku);
    }

    public function testArrayElementNonObjectEmitsTypeErrorWithIndexPath(): void
    {
        try {
            $this->validator->validate(OrderWithItems::class, [
                'sku' => 'PARENT',
                'items' => [
                    ['sku' => 'A'],
                    'not-an-object',
                ],
            ]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors()->all();
            $typeErr = null;
            foreach ($errors as $err) {
                if ($err->rule === 'type' && in_array('1', $err->path, true)) {
                    $typeErr = $err;
                    break;
                }
            }
            self::assertNotNull($typeErr, 'Expected type error on items[1]');
            self::assertSame('', $typeErr->property);
            self::assertSame(['items', '1'], $typeErr->path);
            self::assertSame('/items/1', $typeErr->pointer());
        }
    }
}

final class Address
{
    public function __construct(
        #[Validate('required|email')]
        public ?string $email = null,
    ) {
    }
}

final class CreateOrderRequest
{
    public function __construct(
        #[Validate('required|email')]
        public ?string $email = null,
        #[Validate(Address::class)]
        public ?Address $address = null,
    ) {
    }
}

final class OrderItem
{
    public function __construct(
        #[Validate('required|string|min:1')]
        public ?string $sku = null,
    ) {
    }
}

final class OrderWithItems
{
    /**
     * @param list<OrderItem>|null $items
     */
    public function __construct(
        #[Validate('required|string')]
        public ?string $sku = null,
        #[Validate(items: OrderItem::class)]
        public ?array $items = null,
    ) {
    }
}

final class InnerDto
{
    public function __construct(
        #[Validate('required|email')]
        public ?string $email = null,
    ) {
    }
}

final class MiddleDto
{
    public function __construct(
        #[Validate(InnerDto::class)]
        public ?InnerDto $inner = null,
    ) {
    }
}

final class OuterDto
{
    public function __construct(
        #[Validate(MiddleDto::class)]
        public ?MiddleDto $middle = null,
    ) {
    }
}

final class SimpleClassStringRuleDto
{
    public function __construct(
        #[Validate(\Framework\Validation\Rule\EmailRule::class)]
        public ?string $email = null,
    ) {
    }
}

final class SimpleShorthandDto
{
    public function __construct(
        #[Validate('required|string|min:2')]
        public ?string $name = null,
        #[Validate('required|integer|min:18|max:120')]
        public ?int $age = null,
    ) {
    }
}

final readonly class ReadonlyAddressDto
{
    public function __construct(
        #[Validate('required|email')]
        public ?string $email = null,
    ) {
    }
}

final readonly class ReadonlyParentDto
{
    public function __construct(
        public ?string $label = null,
        #[Validate(ReadonlyAddressDto::class)]
        public ?ReadonlyAddressDto $address = null,
    ) {
    }
}

final readonly class ReadonlyItemDto
{
    public function __construct(
        #[Validate('required|string|min:1')]
        public ?string $sku = null,
    ) {
    }
}

final readonly class ReadonlyItemsParentDto
{
    /**
     * @param list<ReadonlyItemDto>|null $items
     */
    public function __construct(
        public ?string $sku = null,
        #[Validate(items: ReadonlyItemDto::class)]
        public ?array $items = null,
    ) {
    }
}
