<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Exception;

use Framework\Http\Exception\BadGatewayHttpException;
use Framework\Http\Exception\ConflictHttpException;
use Framework\Http\Exception\ForbiddenHttpException;
use Framework\Http\Exception\HttpException;
use Framework\Http\Exception\ServiceUnavailableHttpException;
use Framework\Http\Exception\TooManyRequestsHttpException;
use Framework\Http\Exception\UnauthorizedHttpException;
use Framework\Http\Exception\UnsupportedMediaTypeHttpException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnauthorizedHttpException::class)]
#[CoversClass(ForbiddenHttpException::class)]
#[CoversClass(ConflictHttpException::class)]
#[CoversClass(UnsupportedMediaTypeHttpException::class)]
#[CoversClass(TooManyRequestsHttpException::class)]
#[CoversClass(BadGatewayHttpException::class)]
#[CoversClass(ServiceUnavailableHttpException::class)]
final class MissingHttpExceptionsTest extends TestCase
{
    /**
     * @return array<string, array{HttpException, int, string}>
     */
    public static function exceptionProvider(): array
    {
        return [
            '401 Unauthorized' => [new UnauthorizedHttpException(), 401, 'Unauthorized'],
            '403 Forbidden' => [new ForbiddenHttpException(), 403, 'Forbidden'],
            '409 Conflict' => [new ConflictHttpException(), 409, 'Conflict'],
            '415 Unsupported Media Type' => [new UnsupportedMediaTypeHttpException(), 415, 'Unsupported Media Type'],
            '429 Too Many Requests' => [new TooManyRequestsHttpException(), 429, 'Too Many Requests'],
            '502 Bad Gateway' => [new BadGatewayHttpException(), 502, 'Bad Gateway'],
            '503 Service Unavailable' => [new ServiceUnavailableHttpException(), 503, 'Service Unavailable'],
        ];
    }

    #[DataProvider('exceptionProvider')]
    public function testExtendsHttpException(HttpException $exception): void
    {
        self::assertInstanceOf(HttpException::class, $exception);
    }

    #[DataProvider('exceptionProvider')]
    public function testStatusCodeIsCorrect(HttpException $exception, int $expectedStatus): void
    {
        self::assertSame($expectedStatus, $exception->statusCode);
    }

    #[DataProvider('exceptionProvider')]
    public function testDefaultMessageIsSet(HttpException $exception, int $expectedStatus, string $expectedMessage): void
    {
        self::assertSame($expectedMessage, $exception->getMessage());
    }

    public function testCustomMessagePropagates(): void
    {
        $exception = new UnauthorizedHttpException('Token expired');

        self::assertSame('Token expired', $exception->getMessage());
        self::assertSame(401, $exception->statusCode);
    }
}
