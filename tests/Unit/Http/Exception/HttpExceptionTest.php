<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Exception;

use Framework\Exception\FrameworkException;
use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Exception\HttpException;
use Framework\Http\Exception\InternalServerErrorHttpException;
use Framework\Http\Exception\MethodNotAllowedHttpException;
use Framework\Http\Exception\NotFoundHttpException;
use Framework\Http\Exception\UnprocessableEntityHttpException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(HttpException::class)]
#[CoversClass(NotFoundHttpException::class)]
#[CoversClass(BadRequestHttpException::class)]
#[CoversClass(UnprocessableEntityHttpException::class)]
#[CoversClass(MethodNotAllowedHttpException::class)]
#[CoversClass(InternalServerErrorHttpException::class)]
final class HttpExceptionTest extends TestCase
{
    public function testHttpExceptionExtendsFrameworkException(): void
    {
        $exception = new HttpException(418, "I'm a teapot");

        self::assertInstanceOf(FrameworkException::class, $exception);
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame(418, $exception->statusCode);
        self::assertSame("I'm a teapot", $exception->getMessage());
        self::assertSame(418, $exception->getCode());
    }

    public function testNotFoundHttpExceptionHas404(): void
    {
        $exception = new NotFoundHttpException();

        self::assertInstanceOf(HttpException::class, $exception);
        self::assertSame(404, $exception->statusCode);
        self::assertSame('Not Found', $exception->getMessage());
    }

    public function testBadRequestHttpExceptionHas400(): void
    {
        $exception = new BadRequestHttpException('Invalid input');

        self::assertSame(400, $exception->statusCode);
        self::assertSame('Invalid input', $exception->getMessage());
    }

    public function testUnprocessableEntityHttpExceptionHas422(): void
    {
        $exception = new UnprocessableEntityHttpException();

        self::assertSame(422, $exception->statusCode);
    }

    public function testMethodNotAllowedHttpExceptionHas405(): void
    {
        $exception = new MethodNotAllowedHttpException();

        self::assertSame(405, $exception->statusCode);
    }

    public function testInternalServerErrorHttpExceptionHas500(): void
    {
        $exception = new InternalServerErrorHttpException('Oops');

        self::assertSame(500, $exception->statusCode);
        self::assertSame('Oops', $exception->getMessage());
    }

    public function testPreviousIsPreserved(): void
    {
        $previous = new RuntimeException('original');
        $exception = new HttpException(500, 'wrapped', 'about:blank', $previous);

        self::assertSame($previous, $exception->getPrevious());
    }
}
