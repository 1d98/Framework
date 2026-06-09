<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Request;

use Framework\Http\Request\Request;
use Framework\Http\Request\RequestBinder;
use Framework\Http\Request\RequestMemo;
use Framework\Validation\Attribute\Validate;
use Framework\Validation\Rule\RuleRegistry;
use Framework\Validation\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(Request::class)]
#[CoversClass(RequestBinder::class)]
#[CoversClass(RequestMemo::class)]
final class RequestBinderLazyTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator(new RuleRegistry());
    }

    public function testBindAllocatesBinderOnlyOnceAcross100Calls(): void
    {
        $request = $this->makeRequest()->withJson(['name' => 'Alice', 'age' => 30]);

        $firstDto = $request->bind(LazyBindDto::class);
        $firstBinder = $this->readMemoBinder($request);
        self::assertInstanceOf(RequestBinder::class, $firstBinder);

        for ($i = 0; $i < 100; $i++) {
            $dto = $request->bind(LazyBindDto::class);
            self::assertSame('Alice', $dto->name);
            self::assertSame(30, $dto->age);
        }

        $binderAfter100 = $this->readMemoBinder($request);
        self::assertSame(
            $firstBinder,
            $binderAfter100,
            'RequestBinder must be the same instance across 100 bind() calls',
        );
        self::assertEquals($firstDto, $dto);
    }

    public function testBindWithAllocatesBinderOnlyOnceAcross100Calls(): void
    {
        $request = $this->makeRequest()->withJson(['name' => 'Alice', 'age' => 30]);

        for ($i = 0; $i < 100; $i++) {
            $dto = $request->bindWith(['name' => 'Bob', 'age' => 1], LazyBindDto::class);
            self::assertSame('Bob', $dto->name);
            self::assertSame(1, $dto->age);
        }

        $binder = $this->readMemoBinder($request);
        self::assertInstanceOf(RequestBinder::class, $binder);
    }

    public function testWithBinderUsesCustomBinderAndSkipsLazyDefault(): void
    {
        $customBinder = new RequestBinder(new Validator(new RuleRegistry()));
        $request = $this->makeRequest()
            ->withJson(['name' => 'Alice', 'age' => 30])
            ->withBinder($customBinder);

        $dto = $request->bind(LazyBindDto::class);
        self::assertSame('Alice', $dto->name);

        self::assertSame($customBinder, $this->readMemoBinder($request));
    }

    public function testWithBinderReturnsNewInstanceAndPreservesState(): void
    {
        $customBinder = new RequestBinder(new Validator(new RuleRegistry()));
        $original = $this->makeRequest();
        $with = $original->withBinder($customBinder);

        self::assertNotSame($original, $with);
        self::assertNull($this->readMemoBinder($original));
        self::assertSame($customBinder, $this->readMemoBinder($with));
    }

    public function testWithMethodsPreserveMemoSoBinderStaysShared(): void
    {
        $request = $this->makeRequest()->withJson(['name' => 'Alice', 'age' => 30]);
        $request->bind(LazyBindDto::class);
        $firstBinder = $this->readMemoBinder($request);
        self::assertInstanceOf(RequestBinder::class, $firstBinder);

        $afterWithJson = $request->withJson(['name' => 'X', 'age' => 1]);
        $afterWithForm = $request->withForm(['name' => 'Y']);
        $afterWithCsrf = $request->withCsrfToken('t');

        self::assertSame($firstBinder, $this->readMemoBinder($afterWithJson));
        self::assertSame($firstBinder, $this->readMemoBinder($afterWithForm));
        self::assertSame($firstBinder, $this->readMemoBinder($afterWithCsrf));
    }

    public function testBinderValidatesAcrossManyCalls(): void
    {
        $request = $this->makeRequest()->withJson(['name' => 'Alice', 'age' => 30]);

        for ($i = 0; $i < 100; $i++) {
            $dto = $request->bind(LazyBindDto::class);
            self::assertInstanceOf(LazyBindDto::class, $dto);
        }
    }

    public function testBinderConstructsValidatorLazilyNotInConstructor(): void
    {
        $binder = new RequestBinder();

        $reflection = new ReflectionProperty(RequestBinder::class, 'validator');
        self::assertNull(
            $reflection->getValue($binder),
            'RequestBinder must not construct a default Validator eagerly in its constructor',
        );
    }

    public function testBinderResolvesValidatorLazilyOnFirstBind(): void
    {
        $binder = new RequestBinder();
        $request = $this->makeRequest()->withJson(['name' => 'Alice', 'age' => 30]);

        $dto = $binder->bind($request, LazyBindDto::class);

        self::assertInstanceOf(LazyBindDto::class, $dto);
        self::assertSame('Alice', $dto->name);
    }

    public function testWithBinderPassesCustomValidatorThroughToBind(): void
    {
        $customValidator = new Validator(new RuleRegistry());
        $customBinder = new RequestBinder($customValidator);
        $request = $this->makeRequest()
            ->withJson(['name' => 'Alice', 'age' => 30])
            ->withBinder($customBinder);

        $dto = $request->bind(LazyBindDto::class);
        self::assertSame('Alice', $dto->name);
    }

    public function testBindDelegatesToMemoizedBinderIdenticallyAcrossCalls(): void
    {
        $request = $this->makeRequest()->withJson(['name' => 'Alice', 'age' => 30]);

        $first = $request->bind(LazyBindDto::class);
        $second = $request->bind(LazyBindDto::class);
        $third = $request->bind(LazyBindDto::class);

        self::assertEquals($first, $second);
        self::assertEquals($second, $third);
    }

    private function makeRequest(): Request
    {
        return new Request(
            method: 'POST',
            path: '/api/v1/users',
            validator: $this->validator,
        );
    }

    private function readMemoBinder(Request $request): ?RequestBinder
    {
        $memoReflection = new ReflectionProperty(Request::class, 'memo');
        $memo = $memoReflection->getValue($request);
        self::assertInstanceOf(RequestMemo::class, $memo);
        /** @var RequestMemo $memo */
        $binderReflection = new ReflectionProperty(RequestMemo::class, 'binder');
        $value = $binderReflection->getValue($memo);
        if ($value !== null) {
            self::assertInstanceOf(RequestBinder::class, $value);
        }
        return $value;
    }
}

final class LazyBindDto
{
    public function __construct(
        #[Validate(['required', 'string', 'min:1', 'max:100'])]
        public ?string $name = null,
        #[Validate(['required', 'integer', 'min:0', 'max:150'])]
        public ?int $age = null,
    ) {
    }
}
