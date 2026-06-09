<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Validation;

use Framework\Exception\FrameworkException;
use Framework\Http\Exception\UnprocessableEntityHttpException;
use Framework\Validation\ValidationError;
use Framework\Validation\ValidationErrorCollection;
use Framework\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ValidationException::class)]
final class ValidationExceptionTest extends TestCase
{
    public function testExtendsFrameworkException(): void
    {
        $e = new ValidationException(new ValidationErrorCollection());

        self::assertInstanceOf(FrameworkException::class, $e);
        self::assertInstanceOf(RuntimeException::class, $e);
    }

    public function testDoesNotExtendHttpException(): void
    {
        $parents = class_parents(ValidationException::class);

        self::assertNotContains(UnprocessableEntityHttpException::class, $parents);
        self::assertNotContains(\Framework\Http\Exception\HttpException::class, $parents);
    }

    public function testDefaultMessageIsValidationFailed(): void
    {
        $e = new ValidationException(new ValidationErrorCollection());
        self::assertSame('Validation failed', $e->getMessage());
    }

    public function testHoldsErrorsCollection(): void
    {
        $errors = new ValidationErrorCollection([new ValidationError('email', 'required', 'required')]);
        $e = new ValidationException($errors);

        self::assertSame($errors, $e->errors());
        self::assertCount(1, $e->errors());
    }

    public function testPreviousThrowableIsPreserved(): void
    {
        $previous = new RuntimeException('upstream');
        $e = new ValidationException(new ValidationErrorCollection(), $previous);

        self::assertSame($previous, $e->getPrevious());
    }
}
