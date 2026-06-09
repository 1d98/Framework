<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Request;

use Framework\Http\Request\Request;
use Framework\Http\Request\RequestBinder;
use Framework\Validation\Attribute\Validate;
use Framework\Validation\Rule\RuleRegistry;
use Framework\Validation\ValidationException;
use Framework\Validation\Validator;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestBinder::class)]
#[CoversClass(Request::class)]
final class RequestBinderTest extends TestCase
{
    public function testBinderReadsJsonAndFallsBackToForm(): void
    {
        $binder = new RequestBinder(new Validator(new RuleRegistry()));

        $fromJson = $binder->bind($this->requestWith(['name' => 'Alice', 'age' => 30]), BinderDto::class);
        self::assertSame('Alice', $fromJson->name);
        self::assertSame(30, $fromJson->age);

        $fromForm = $binder->bind($this->requestWith(null, ['name' => 'Bob']), BinderSimpleFormDto::class);
        self::assertSame('Bob', $fromForm->name);
    }

    public function testBinderJsonWinsOverForm(): void
    {
        $request = $this->requestWith(['name' => 'Alice', 'age' => 30]);
        $request = $request->withForm(['name' => 'Bob']);

        $binder = new RequestBinder(new Validator(new RuleRegistry()));
        $dto = $binder->bind($request, BinderDto::class);

        self::assertSame('Alice', $dto->name);
    }

    public function testBinderWithExplicitDataIgnoresRequestBody(): void
    {
        $request = $this->requestWith(['name' => 'FromJson', 'age' => 1]);
        $binder = new RequestBinder(new Validator(new RuleRegistry()));

        $dto = $binder->bindWith($request, ['name' => 'Explicit', 'age' => 50], BinderDto::class);

        self::assertSame('Explicit', $dto->name);
        self::assertSame(50, $dto->age);
    }

    public function testBinderThrowsValidationExceptionOnInvalidPayload(): void
    {
        $request = $this->requestWith(['name' => '', 'age' => -1]);
        $binder = new RequestBinder(new Validator(new RuleRegistry()));

        $this->expectException(ValidationException::class);
        $binder->bind($request, BinderDto::class);
    }

    public function testRequestBindDelegatesToRequestBinder(): void
    {
        $request = $this->requestWith(['name' => 'Alice', 'age' => 30])->withValidator(new Validator(new RuleRegistry()));
        $binder = new RequestBinder(new Validator(new RuleRegistry()));

        $dto = $binder->bind($request, BinderDto::class);

        self::assertEquals($dto, $request->bind(BinderDto::class));
    }

    public function testRequestBindWithoutValidatorStillThrowsLogicException(): void
    {
        $request = new Request('POST', '/api/v1/users');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Validator not configured');
        $request->bind(BinderDto::class);
    }

    public function testRequestBindWithWithoutValidatorStillThrowsLogicException(): void
    {
        $request = new Request('POST', '/api/v1/users');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Validator not configured');
        $request->bindWith(['name' => 'X', 'age' => 1], BinderDto::class);
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string|list<string>>|null $form
     */
    private function requestWith(?array $json = null, ?array $form = null): Request
    {
        return new Request('POST', '/api/v1/users', json: $json, form: $form);
    }
}

final class BinderDto
{
    public function __construct(
        #[Validate(['required', 'string', 'min:1', 'max:100'])]
        public ?string $name = null,
        #[Validate(['required', 'integer', 'min:0', 'max:150'])]
        public ?int $age = null,
    ) {
    }
}

final class BinderSimpleFormDto
{
    public function __construct(
        #[Validate(['required', 'string', 'min:1', 'max:100'])]
        public ?string $name = null,
    ) {
    }
}
