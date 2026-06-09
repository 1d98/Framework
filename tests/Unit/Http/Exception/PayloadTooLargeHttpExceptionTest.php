<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Exception;

use Framework\Http\Exception\HttpException;
use Framework\Http\Exception\PayloadTooLargeHttpException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PayloadTooLargeHttpException::class)]
final class PayloadTooLargeHttpExceptionTest extends TestCase
{
    public function testStatusCodeIs413(): void
    {
        $exception = new PayloadTooLargeHttpException();

        self::assertSame(413, $exception->statusCode);
    }

    public function testTitleIsPayloadTooLarge(): void
    {
        $exception = new PayloadTooLargeHttpException();

        self::assertSame('Payload Too Large', $exception->title);
    }

    public function testIsHttpException(): void
    {
        $exception = new PayloadTooLargeHttpException();

        self::assertInstanceOf(HttpException::class, $exception);
    }

    public function testDefaultMessage(): void
    {
        $exception = new PayloadTooLargeHttpException();

        self::assertSame('Payload Too Large', $exception->getMessage());
    }

    public function testCustomMessage(): void
    {
        $exception = new PayloadTooLargeHttpException('Request body too large');

        self::assertSame('Request body too large', $exception->getMessage());
        self::assertSame(413, $exception->statusCode);
    }

    public function testHeadersDefaultEmpty(): void
    {
        $exception = new PayloadTooLargeHttpException();

        self::assertSame([], $exception->headers());
    }
}
