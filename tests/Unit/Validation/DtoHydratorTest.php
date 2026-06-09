<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Validation;

use Framework\Validation\Attribute\From;
use Framework\Validation\Attribute\Validate;
use Framework\Validation\DtoHydrator;
use Framework\Validation\Rule\RuleRegistry;
use Framework\Validation\RuleResolver;
use Framework\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

#[CoversClass(DtoHydrator::class)]
final class DtoHydratorTest extends TestCase
{
    private DtoHydrator $hydrator;

    protected function setUp(): void
    {
        \Framework\Validation\Validator::clearCaches();
        $this->hydrator = new DtoHydrator(new RuleResolver(new RuleRegistry()));
    }

    protected function tearDown(): void
    {
        \Framework\Validation\Validator::clearCaches();
    }

    public function testHydrateUsesConstructorWhenPresent(): void
    {
        $dto = $this->hydrator->hydrate(HydratorCtorDto::class, [
            'email' => 'a@b.com',
            'password' => 'secret',
        ]);

        self::assertInstanceOf(HydratorCtorDto::class, $dto);
        self::assertSame('a@b.com', $dto->email);
        self::assertSame('secret', $dto->password);
    }

    public function testHydrateFallsBackToPropertySetWhenNoConstructor(): void
    {
        $dto = $this->hydrator->hydrate(HydratorLegacyNoCtorDto::class, ['name' => 'Alice']);
        self::assertSame('Alice', $dto->name);
    }

    public function testHydrateFallsBackToPropertySetWhenZeroArgConstructor(): void
    {
        $dto = $this->hydrator->hydrate(HydratorZeroArgCtorDto::class, ['name' => 'Bob']);
        self::assertSame('Bob', $dto->name);
    }

    public function testHydrateInvokesNonTrivialConstructor(): void
    {
        $dto = $this->hydrator->hydrate(HydratorNormalizingDto::class, ['email' => 'Alice@Example.COM']);
        self::assertSame('alice@example.com', $dto->email);
    }

    public function testWalkDottedPathResolvesExactCase(): void
    {
        $value = $this->invokeWalkDottedPath(
            ['user' => ['email' => 'a@b.com']],
            ['user', 'email'],
        );
        self::assertSame('a@b.com', $value);
    }

    public function testWalkDottedPathIsCaseInsensitiveOuter(): void
    {
        $value = $this->invokeWalkDottedPath(
            ['User' => ['email' => 'a@b.com']],
            ['user', 'email'],
        );
        self::assertSame('a@b.com', $value);
    }

    public function testWalkDottedPathIsCaseInsensitiveInner(): void
    {
        $value = $this->invokeWalkDottedPath(
            ['user' => ['Email' => 'a@b.com']],
            ['user', 'email'],
        );
        self::assertSame('a@b.com', $value);
    }

    public function testWalkDottedPathReturnsMissingSentinelForUnknownKey(): void
    {
        $value = $this->invokeWalkDottedPath(
            ['user' => ['name' => 'Alice']],
            ['user', 'email'],
        );
        self::assertSame($this->missingSentinel(), $value);
    }

    public function testWalkDottedPathReturnsMissingSentinelForNonArrayStep(): void
    {
        $value = $this->invokeWalkDottedPath(
            ['user' => 'not-an-array'],
            ['user', 'email'],
        );
        self::assertSame($this->missingSentinel(), $value);
    }

    public function testNestedDtoClassForResolvesFromValidateAttribute(): void
    {
        $dto = new HydratorProfileDto('Alice', null);
        $reflection = new ReflectionClass(HydratorProfileDto::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);

        $addressParam = null;
        foreach ($constructor->getParameters() as $param) {
            if ($param->getName() === 'address') {
                $addressParam = $param;
                break;
            }
        }
        self::assertNotNull($addressParam);

        $class = $this->invokeNestedDtoClassFor($addressParam, ['email' => 'x@y.com']);
        self::assertSame(HydratorAddressDto::class, $class);
    }

    public function testItemsClassForResolvesFromValidateAttribute(): void
    {
        $dto = new HydratorCartDto(null);
        $reflection = new ReflectionClass(HydratorCartDto::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);

        $itemsParam = $constructor->getParameters()[0];
        self::assertSame($itemsParam->getName(), 'items');

        $class = $this->invokeItemsClassFor($itemsParam);
        self::assertSame(HydratorItemDto::class, $class);
    }

    public function testHydrateResolvesFromAttribute(): void
    {
        $dto = $this->hydrator->hydrate(HydratorAliasedDto::class, [
            'user' => ['email' => 'aliased@example.com'],
        ]);
        self::assertSame('aliased@example.com', $dto->email);
    }

    public function testHydrateMissingRequiredParameterThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->hydrator->hydrate(HydratorRequiredCtorDto::class, []);
    }

    public function testHydrateUsesDefaultForMissingOptionalParameter(): void
    {
        $dto = $this->hydrator->hydrate(HydratorDefaultedDto::class, ['email' => 'a@b.com']);
        self::assertSame(18, $dto->age);
    }

    public function testHydrateRecursesIntoNestedDto(): void
    {
        $dto = $this->hydrator->hydrate(HydratorProfileDto::class, [
            'name' => 'Alice',
            'address' => ['email' => 'addr@example.com'],
        ]);
        self::assertInstanceOf(HydratorAddressDto::class, $dto->address);
        self::assertSame('addr@example.com', $dto->address->email);
    }

    public function testHydrateRecursesIntoItemsList(): void
    {
        $dto = $this->hydrator->hydrate(HydratorCartDto::class, [
            'items' => [
                ['sku' => 'A'],
                ['sku' => 'B'],
            ],
        ]);
        self::assertNotNull($dto->items);
        self::assertCount(2, $dto->items);
        self::assertInstanceOf(HydratorItemDto::class, $dto->items[0]);
        self::assertSame('A', $dto->items[0]->sku);
        self::assertSame('B', $dto->items[1]->sku);
    }

    public function testHydrateCaseInsensitiveParamName(): void
    {
        $dto = $this->hydrator->hydrate(HydratorCtorDto::class, [
            'Email' => 'x@y.com',
            'PASSWORD' => 'pw',
        ]);
        self::assertSame('x@y.com', $dto->email);
        self::assertSame('pw', $dto->password);
    }

    public function testHydrateMissingOptionalUsesDefaultWhenAvailable(): void
    {
        $dto = $this->hydrator->hydrate(HydratorZeroArgCtorDto::class, []);
        self::assertNull($dto->name);
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string> $segments
     */
    private function invokeWalkDottedPath(array $data, array $segments): mixed
    {
        $ref = new ReflectionMethod(DtoHydrator::class, 'walkDottedPath');
        return $ref->invoke($this->hydrator, $data, $segments);
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function invokeNestedDtoClassFor(ReflectionParameter $param, array $value): ?string
    {
        $ref = new ReflectionMethod(DtoHydrator::class, 'nestedDtoClassFor');
        $value = $ref->invoke($this->hydrator, $param, $value);
        return is_string($value) ? $value : null;
    }

    private function invokeItemsClassFor(ReflectionParameter $param): ?string
    {
        $ref = new ReflectionMethod(DtoHydrator::class, 'itemsClassFor');
        $value = $ref->invoke($this->hydrator, $param);
        return is_string($value) ? $value : null;
    }

    private function missingSentinel(): string
    {
        $ref = new ReflectionClass(DtoHydrator::class);
        $const = $ref->getReflectionConstant('MISSING');
        $value = $const ? $const->getValue() : '';
        return is_string($value) ? $value : '';
    }
}

final class HydratorCtorDto
{
    public function __construct(
        public string $email,
        public string $password,
    ) {
    }
}

final class HydratorLegacyNoCtorDto
{
    public ?string $name = null;
}

final class HydratorZeroArgCtorDto
{
    public ?string $name = null;

    public function __construct()
    {
    }
}

final class HydratorNormalizingDto
{
    public function __construct(public string $email)
    {
        $this->email = strtolower($email);
    }
}

final class HydratorRequiredCtorDto
{
    public function __construct(public string $email)
    {
    }
}

final class HydratorDefaultedDto
{
    public function __construct(public string $email, public int $age = 18)
    {
    }
}

final class HydratorAliasedDto
{
    public function __construct(
        #[From('user.email')]
        public string $email,
    ) {
    }
}

final class HydratorAddressDto
{
    public function __construct(
        #[Validate('required|string')]
        public ?string $email = null,
    ) {
    }
}

final class HydratorProfileDto
{
    public function __construct(
        public string $name,
        #[Validate(HydratorAddressDto::class)]
        public ?HydratorAddressDto $address = null,
    ) {
    }
}

final class HydratorItemDto
{
    public function __construct(public ?string $sku = null)
    {
    }
}

final class HydratorCartDto
{
    /**
     * @param list<HydratorItemDto>|null $items
     */
    public function __construct(
        #[Validate(items: HydratorItemDto::class)]
        public ?array $items = null,
    ) {
    }
}
