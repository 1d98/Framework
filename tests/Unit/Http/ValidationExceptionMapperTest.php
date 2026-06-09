<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Http\Exception\UnprocessableEntityHttpException;
use Framework\Http\ValidationExceptionMapper;
use Framework\Validation\ValidationError;
use Framework\Validation\ValidationErrorCollection;
use Framework\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ValidationExceptionMapper::class)]
final class ValidationExceptionMapperTest extends TestCase
{
    public function testProducesUnprocessableEntityHttpException(): void
    {
        $http = ValidationExceptionMapper::toHttpException(
            new ValidationException(new ValidationErrorCollection()),
        );

        self::assertInstanceOf(UnprocessableEntityHttpException::class, $http);
        self::assertSame(422, $http->statusCode);
    }

    public function testPreservesMessage(): void
    {
        $http = ValidationExceptionMapper::toHttpException(
            new ValidationException(new ValidationErrorCollection()),
        );

        self::assertSame('Validation failed', $http->getMessage());
    }

    public function testPreservesErrorsAsRfc7807Array(): void
    {
        $errors = new ValidationErrorCollection([
            new ValidationError('email', 'required', 'Field is required'),
            new ValidationError('age', 'min', 'Must be at least 18', 5, ['profile']),
        ]);

        $http = ValidationExceptionMapper::toHttpException(new ValidationException($errors));

        $rendered = $http->errors();
        self::assertCount(2, $rendered);

        self::assertSame('email', $rendered[0]['property']);
        self::assertSame('required', $rendered[0]['rule']);
        self::assertSame('Field is required', $rendered[0]['message']);
        self::assertSame('/email', $rendered[0]['pointer']);

        self::assertSame('age', $rendered[1]['property']);
        self::assertSame('min', $rendered[1]['rule']);
        self::assertSame(5, $rendered[1]['value']);
        self::assertSame('/profile/age', $rendered[1]['pointer']);
    }

    public function testEmptyErrorCollectionYieldsEmptyErrorsList(): void
    {
        $http = ValidationExceptionMapper::toHttpException(
            new ValidationException(new ValidationErrorCollection()),
        );

        self::assertSame([], $http->errors());
    }

    public function testPreservesPreviousThrowable(): void
    {
        $previous = new RuntimeException('upstream');
        $http = ValidationExceptionMapper::toHttpException(
            new ValidationException(new ValidationErrorCollection(), $previous),
        );

        self::assertSame($previous, $http->getPrevious());
    }

    public function testOriginalExceptionIsNotHttpShaped(): void
    {
        $parents = class_parents(ValidationException::class);

        self::assertNotContains(UnprocessableEntityHttpException::class, $parents);
    }
}
