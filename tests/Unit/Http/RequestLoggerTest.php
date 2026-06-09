<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Exception\InternalServerErrorHttpException;
use Framework\Http\Exception\NotFoundHttpException;
use Framework\Http\Request\Request;
use Framework\Http\RequestLogger;
use Framework\Http\Response\Response;
use Framework\Logging\NullLogger;
use Framework\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RequestLogger::class)]
final class RequestLoggerTest extends TestCase
{
    public function testFourXxHttpExceptionLogsWarning(): void
    {
        $logger = new RecordingLogger();
        $requestLogger = new RequestLogger($logger);
        $request = new Request('POST', '/bad', '', [], '', null, null, null, [], null, null, null, 'corr-1');
        $response = new Response(400);

        $requestLogger->logHttpException(new BadRequestHttpException('email is invalid'), $request, $response);

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('http_exception', $logger->records[0]['message']);
        self::assertSame(400, $logger->records[0]['context']['status']);
        self::assertSame('POST', $logger->records[0]['context']['method']);
        self::assertSame('/bad', $logger->records[0]['context']['path']);
        self::assertSame('corr-1', $logger->records[0]['context']['request_id']);
        self::assertSame(BadRequestHttpException::class, $logger->records[0]['context']['exception']);
        self::assertSame('email is invalid', $logger->records[0]['context']['message']);
    }

    public function testFiveXxHttpExceptionLogsError(): void
    {
        $logger = new RecordingLogger();
        $requestLogger = new RequestLogger($logger);
        $request = new Request('GET', '/fail', '', [], '', null, null, null, [], null, null, null, 'corr-2');
        $response = new Response(500);

        $requestLogger->logHttpException(new InternalServerErrorHttpException('db down'), $request, $response);

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('http_exception', $logger->records[0]['message']);
        self::assertSame(500, $logger->records[0]['context']['status']);
        self::assertSame('corr-2', $logger->records[0]['context']['request_id']);
    }

    public function testRouteNotFoundHttpExceptionLogsWarning(): void
    {
        $logger = new RecordingLogger();
        $requestLogger = new RequestLogger($logger);
        $request = new Request('GET', '/missing', '', [], '', null, null, null, [], null, null, null, 'corr-3');
        $response = new Response(404);

        $requestLogger->logHttpException(new NotFoundHttpException('no route'), $request, $response);

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(404, $logger->records[0]['context']['status']);
    }

    public function testLogHttpExceptionIsNoOpForNonHttpException(): void
    {
        $logger = new RecordingLogger();
        $requestLogger = new RequestLogger($logger);
        $request = new Request('GET', '/crash', '', [], '', null, null, null, [], null, null, null, 'corr-4');
        $response = new Response(500);

        $requestLogger->logHttpException(new RuntimeException('boom'), $request, $response);

        self::assertCount(0, $logger->records);
    }

    public function testLogUnhandledExceptionLogsError(): void
    {
        $logger = new RecordingLogger();
        $requestLogger = new RequestLogger($logger);
        $request = new Request('GET', '/crash', '', [], '', null, null, null, [], null, null, null, 'corr-5');
        $response = new Response(500);

        $requestLogger->logUnhandledException(new RuntimeException('boom'), $request, $response);

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('unhandled_exception', $logger->records[0]['message']);
        self::assertSame(500, $logger->records[0]['context']['status']);
        self::assertSame(RuntimeException::class, $logger->records[0]['context']['exception']);
        self::assertSame('boom', $logger->records[0]['context']['message']);
        self::assertSame('corr-5', $logger->records[0]['context']['request_id']);
    }

    public function testNullLoggerIsNoOp(): void
    {
        $requestLogger = new RequestLogger(null);
        $request = new Request('GET', '/x');
        $response = new Response(500);

        $requestLogger->logHttpException(new BadRequestHttpException('nope'), $request, $response);
        $requestLogger->logUnhandledException(new RuntimeException('boom'), $request, $response);

        self::assertNull($requestLogger->logger());
    }

    public function testNullLoggerGetterReturnsNull(): void
    {
        $requestLogger = new RequestLogger(null);
        self::assertNull($requestLogger->logger());
    }

    public function testLoggerGetterReturnsProvidedLogger(): void
    {
        $logger = new NullLogger();
        $requestLogger = new RequestLogger($logger);

        self::assertSame($logger, $requestLogger->logger());
    }

    public function testLogContextIncludesRequestId(): void
    {
        $requestLogger = new RequestLogger(null);
        $request = new Request('GET', '/x', '', [], '', null, null, null, [], null, null, null, 'log-corr');

        $context = $requestLogger->logContext($request);

        self::assertSame('log-corr', $context['request_id']);
        self::assertSame('GET', $context['method']);
        self::assertSame('/x', $context['path']);
        self::assertSame(500, $context['status']);
        self::assertNull($context['exception']);
        self::assertSame('', $context['message']);
    }

    public function testLogContextWithHttpExceptionDerivesStatus(): void
    {
        $requestLogger = new RequestLogger(null);
        $request = new Request('POST', '/bad');

        $context = $requestLogger->logContext($request, new BadRequestHttpException('email invalid'));

        self::assertSame(400, $context['status']);
        self::assertSame(BadRequestHttpException::class, $context['exception']);
        self::assertSame('email invalid', $context['message']);
    }

    public function testLogContextWithGenericThrowableReturns500Status(): void
    {
        $requestLogger = new RequestLogger(null);
        $request = new Request('GET', '/crash');

        $context = $requestLogger->logContext($request, new RuntimeException('boom'));

        self::assertSame(500, $context['status']);
        self::assertSame(RuntimeException::class, $context['exception']);
        self::assertSame('boom', $context['message']);
    }

    public function testLogContextWithNoExceptionHasNullFields(): void
    {
        $requestLogger = new RequestLogger(null);
        $request = new Request('GET', '/x', '', [], '', null, null, null, [], null, null, null, 'null-corr');

        $context = $requestLogger->logContext($request);

        self::assertSame('null-corr', $context['request_id']);
        self::assertNull($context['exception']);
        self::assertSame('', $context['message']);
    }

    public function testRecordingLoggerCapturesRequestIdConsistently(): void
    {
        $logger = new RecordingLogger();
        $requestLogger = new RequestLogger($logger);
        $request = new Request('GET', '/missing', '', [], '', null, null, null, [], null, null, null, 'integrated-id');
        $response = new Response(404);

        $requestLogger->logHttpException(new NotFoundHttpException('no route'), $request, $response);

        self::assertCount(1, $logger->records);
        self::assertSame('integrated-id', $logger->records[0]['context']['request_id']);
    }

    public function testLogContextSanitizesNewlineInMessage(): void
    {
        $requestLogger = new RequestLogger(null);
        $request = new Request('GET', '/x');

        $context = $requestLogger->logContext($request, new RuntimeException("line1\nline2"));

        self::assertSame('line1 line2', $context['message']);
    }

    public function testLogContextSanitizesCarriageReturnNewlineInMessage(): void
    {
        $requestLogger = new RequestLogger(null);
        $request = new Request('GET', '/x');

        $context = $requestLogger->logContext($request, new RuntimeException("crlf\r\ninjection"));

        self::assertSame('crlf  injection', $context['message']);
        self::assertStringNotContainsString("\r", $context['message']);
        self::assertStringNotContainsString("\n", $context['message']);
    }

    public function testLogContextStripsControlCharNullByte(): void
    {
        $requestLogger = new RequestLogger(null);
        $request = new Request('GET', '/x');

        $context = $requestLogger->logContext($request, new RuntimeException("evil\x00payload"));

        self::assertSame('evilpayload', $context['message']);
    }

    public function testLogContextStripsTabAsWhitespace(): void
    {
        $requestLogger = new RequestLogger(null);
        $request = new Request('GET', '/x');

        $context = $requestLogger->logContext($request, new RuntimeException("col1\tcol2"));

        self::assertSame('col1 col2', $context['message']);
    }

    public function testLogContextTruncatesLongMessageAt256Chars(): void
    {
        $requestLogger = new RequestLogger(null);
        $request = new Request('GET', '/x');
        $long = str_repeat('A', 1000);

        $context = $requestLogger->logContext($request, new RuntimeException($long));

        $expected = str_repeat('A', 256);
        self::assertSame($expected, $context['message']);
        self::assertSame(256, strlen((string) $context['message']));
    }

    public function testLogContextPreservesMessageUnder256Chars(): void
    {
        $requestLogger = new RequestLogger(null);
        $request = new Request('GET', '/x');
        $short = str_repeat('B', 200);

        $context = $requestLogger->logContext($request, new RuntimeException($short));

        self::assertSame($short, $context['message']);
    }

    public function testLogContextWithEmptyExceptionMessageReturnsEmptyString(): void
    {
        $requestLogger = new RequestLogger(null);
        $request = new Request('GET', '/x');

        $context = $requestLogger->logContext($request, new RuntimeException(''));

        self::assertSame('', $context['message']);
    }

    public function testLogContextWithNullExceptionProducesEmptyMessage(): void
    {
        $requestLogger = new RequestLogger(null);
        $request = new Request('GET', '/x', '', [], '', null, null, null, [], null, null, null, 'no-throw');

        $context = $requestLogger->logContext($request);

        self::assertSame('', $context['message']);
        self::assertNull($context['exception']);
    }
}
