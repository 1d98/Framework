<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Request;

use Framework\Http\Request\Request;
use Framework\Validation\Attribute\Validate;
use Framework\Validation\Rule\RuleRegistry;
use Framework\Validation\ValidationException;
use Framework\Validation\Validator;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
final class RequestBindTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator(new RuleRegistry());
    }

    public function testBindReadsJsonBody(): void
    {
        $request = $this->makeRequest()->withJson(['name' => 'Alice', 'age' => 30]);
        $dto = $request->bind(BindDto::class);

        self::assertSame('Alice', $dto->name);
        self::assertSame(30, $dto->age);
    }

    public function testBindFallsBackToFormWhenJsonIsNull(): void
    {
        $request = $this->makeRequest()->withForm(['name' => 'Bob']);
        $dto = $request->bind(SimpleFormDto::class);

        self::assertSame('Bob', $dto->name);
    }

    public function testBindJsonWinsOverForm(): void
    {
        $request = $this->makeRequest()
            ->withJson(['name' => 'Alice', 'age' => 30])
            ->withForm(['name' => 'Bob']);
        $dto = $request->bind(BindDto::class);

        self::assertSame('Alice', $dto->name);
        self::assertSame(30, $dto->age);
    }

    public function testBindThrowsValidationExceptionOnInvalid(): void
    {
        $request = $this->makeRequest()->withJson(['name' => '', 'age' => -1]);
        $this->expectException(ValidationException::class);
        $request->bind(BindDto::class);
    }

    public function testBindWithoutValidatorThrowsLogicException(): void
    {
        $request = new Request('POST', '/api/v1/users');
        $this->expectException(LogicException::class);
        $request->bind(BindDto::class);
    }

    public function testBindWithUsesProvidedDataIgnoringRequest(): void
    {
        $request = $this->makeRequest()->withJson(['name' => 'JsonName', 'age' => 30]);
        $dto = $request->bindWith(['name' => 'ExplicitName', 'age' => 50], BindDto::class);

        self::assertSame('ExplicitName', $dto->name);
        self::assertSame(50, $dto->age);
    }

    public function testBindWithWithoutValidatorThrowsLogicException(): void
    {
        $request = new Request('POST', '/api/v1/users');
        $this->expectException(LogicException::class);
        $request->bindWith(['name' => 'X', 'age' => 1], BindDto::class);
    }

    public function testWithValidatorReturnsNewInstanceWithValidator(): void
    {
        $request = new Request('POST', '/x');
        $with = $request->withValidator($this->validator);
        self::assertNotSame($request, $with);
        self::assertNull($request->validator());
        self::assertSame($this->validator, $with->validator());
    }

    public function testWithMethodsPreserveValidator(): void
    {
        $request = $this->makeRequest()
            ->withJson(['x' => 1])
            ->withForm(['x' => 'y'])
            ->withFiles([])
            ->withCsrfToken('tok');

        self::assertSame($this->validator, $request->validator());
    }

    public function testBindWithEmptyDataFailsRequired(): void
    {
        $request = $this->makeRequest();
        $this->expectException(ValidationException::class);
        $request->bind(BindDto::class);
    }

    private function makeRequest(): Request
    {
        return new Request(
            method: 'POST',
            path: '/api/v1/users',
            validator: $this->validator,
        );
    }
}

final class BindDto
{
    public function __construct(
        #[Validate(['required', 'string', 'min:1', 'max:100'])]
        public ?string $name = null,
        #[Validate(['required', 'integer', 'min:0', 'max:150'])]
        public ?int $age = null,
    ) {
    }
}

final class SimpleFormDto
{
    public function __construct(
        #[Validate(['required', 'string', 'min:1', 'max:100'])]
        public ?string $name = null,
    ) {
    }
}
