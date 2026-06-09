<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Problem;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Exception\HttpException;
use Framework\Http\Exception\MethodNotAllowedHttpException;
use Framework\Http\Exception\NotFoundHttpException;
use Framework\Http\Exception\UnprocessableEntityHttpException;
use Framework\Http\Problem\ProblemDetails;
use Framework\Http\Response\Response;
use Framework\Http\ValidationExceptionMapper;
use Framework\Validation\ValidationError;
use Framework\Validation\ValidationErrorCollection;
use Framework\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TypeError;

#[CoversClass(ProblemDetails::class)]
final class ProblemDetailsTest extends TestCase
{
    public function testNotFoundHttpExceptionInProductionHasNoTrace(): void
    {
        $problem = new ProblemDetails(
            new NotFoundHttpException('missing resource'),
            '/missing',
            false,
        );

        self::assertSame(404, $problem->status());
        self::assertSame('Not Found', $problem->title());
        self::assertSame('missing resource', $problem->detail());
        self::assertNull($problem->trace());
        self::assertNull($problem->errors());
    }

    public function testNotFoundHttpExceptionInDebugAlsoHasNoTraceForFourXx(): void
    {
        $problem = new ProblemDetails(
            new NotFoundHttpException('missing resource'),
            '/missing',
            true,
        );

        self::assertSame(404, $problem->status());
        self::assertSame('Not Found', $problem->title());
        self::assertSame('missing resource', $problem->detail());
        self::assertNull($problem->trace());
    }

    public function testRuntimeExceptionInDebugExposesTraceForFiveHundred(): void
    {
        $problem = new ProblemDetails(
            new RuntimeException('boom'),
            '/crash',
            true,
        );

        self::assertSame(500, $problem->status());
        self::assertSame('Internal Server Error', $problem->title());
        self::assertSame('boom', $problem->detail());

        $trace = $problem->trace();
        self::assertIsArray($trace);
        self::assertNotEmpty($trace);
        foreach ($trace as $frame) {
            self::assertIsArray($frame);
            self::assertArrayHasKey('file', $frame);
            self::assertArrayHasKey('line', $frame);
            self::assertArrayHasKey('function', $frame);
            self::assertArrayHasKey('class', $frame);
            self::assertArrayHasKey('type', $frame);
        }
    }

    public function testRuntimeExceptionInProductionHidesTraceAndMessage(): void
    {
        $problem = new ProblemDetails(
            new RuntimeException('boom'),
            '/crash',
            false,
        );

        self::assertSame(500, $problem->status());
        self::assertSame('Internal Server Error', $problem->title());
        self::assertSame('Internal Server Error', $problem->detail());
        self::assertNull($problem->trace());
    }

    public function testTypeErrorInDebugExposesTraceConsistently(): void
    {
        $problem = new ProblemDetails(
            new TypeError('argument 1 must be int'),
            '/crash',
            true,
        );

        self::assertSame(500, $problem->status());
        self::assertSame('Internal Server Error', $problem->title());
        self::assertSame('argument 1 must be int', $problem->detail());

        $trace = $problem->trace();
        self::assertIsArray($trace);
        self::assertNotEmpty($trace);
        foreach ($trace as $frame) {
            self::assertIsArray($frame);
        }
    }

    public function testDebugTraceStripsAbsolutePathsToBasenames(): void
    {
        $exception = $this->throwAtKnownPath();

        $problem = new ProblemDetails($exception, '/crash', true);
        $trace = $problem->trace();

        self::assertIsArray($trace);
        self::assertNotEmpty($trace);
        $frame = $trace[0];
        self::assertIsArray($frame);
        self::assertSame(basename(__FILE__), $frame['file']);
        self::assertSame('ProblemDetailsTest.php', $frame['file']);
        self::assertIsInt($frame['line']);
        self::assertGreaterThan(0, $frame['line']);
        $encoded = json_encode($frame);
        self::assertIsString($encoded);
        self::assertStringNotContainsString(__FILE__, $encoded);
    }

    public function testDebugTraceResponseBodyHasNoAbsolutePaths(): void
    {
        $exception = $this->throwAtKnownPath();

        $problem = new ProblemDetails($exception, '/crash', true);
        $body = $problem->toArray();

        self::assertArrayHasKey('trace', $body);
        $serialized = json_encode($body);
        self::assertIsString($serialized);
        self::assertStringNotContainsString(__FILE__, $serialized);
        self::assertStringContainsString('ProblemDetailsTest.php', $serialized);
    }

    public function testDebugTraceFramesWithoutFileUseInternalSentinel(): void
    {
        $problem = new ProblemDetails(new RuntimeException('boom'), '/crash', true);
        $trace = $problem->trace();

        self::assertIsArray($trace);
        self::assertNotEmpty($trace);
        foreach ($trace as $frame) {
            self::assertIsArray($frame);
            self::assertIsString($frame['file']);
        }
    }

    private function throwAtKnownPath(): RuntimeException
    {
        try {
            throw new RuntimeException('boom');
        } catch (RuntimeException $e) {
            return $e;
        }
    }

    public function testProductionModeDoesNotIncludeTrace(): void
    {
        $problem = new ProblemDetails(new RuntimeException('boom'), '/crash', false);

        self::assertNull($problem->trace());
        self::assertArrayNotHasKey('trace', $problem->toArray());
    }

    public function testDebugModeFourXxHttpExceptionHasNoTrace(): void
    {
        $problem = new ProblemDetails(new BadRequestHttpException('nope'), '/x', true);

        self::assertNull($problem->trace());
    }

    public function testMethodNotAllowedHttpExceptionEmitsAllowHeaderFromAllowedMethods(): void
    {
        $problem = new ProblemDetails(
            new MethodNotAllowedHttpException('blocked', null, ['GET', 'POST']),
            '/x',
            false,
        );

        self::assertSame(405, $problem->status());
        self::assertSame('Method Not Allowed', $problem->title());
        self::assertSame('GET, POST', $problem->headers()['Allow']);
    }

    public function testValidationExceptionProducesErrorsWithPointerAndPath(): void
    {
        $errors = new ValidationErrorCollection([
            new ValidationError('email', 'required', 'Field is required'),
            new ValidationError('age', 'min', 'Must be at least 18', 5, ['profile']),
        ]);

        $problem = new ProblemDetails(
            ValidationExceptionMapper::toHttpException(new ValidationException($errors)),
            '/users',
            false,
        );

        self::assertSame(422, $problem->status());
        self::assertSame('Unprocessable Entity', $problem->title());

        $rendered = $problem->errors();
        self::assertIsArray($rendered);
        self::assertCount(2, $rendered);

        self::assertSame('email', $rendered[0]['property']);
        self::assertSame('required', $rendered[0]['rule']);
        self::assertSame('/email', $rendered[0]['pointer']);
        self::assertSame([], $rendered[0]['path']);

        self::assertSame('age', $rendered[1]['property']);
        self::assertSame('min', $rendered[1]['rule']);
        self::assertSame(5, $rendered[1]['value']);
        self::assertSame('/profile/age', $rendered[1]['pointer']);
        self::assertSame(['profile'], $rendered[1]['path']);
    }

    public function testTypeFallsBackToAboutBlankForNonHttpException(): void
    {
        $problem = new ProblemDetails(new RuntimeException('x'), '/', false);

        self::assertSame('about:blank', $problem->type());
    }

    public function testTypeFallsBackToAboutBlankForHttpExceptionWithDefaultType(): void
    {
        $problem = new ProblemDetails(new NotFoundHttpException('x'), '/', false);

        self::assertSame('about:blank', $problem->type());
    }

    public function testCustomHttpExceptionTypeIsPreserved(): void
    {
        $exception = new class ('detail', 422) extends HttpException {
            public function __construct(string $message, int $status)
            {
                parent::__construct($status, $message, 'https://example.com/probs/out-of-credit');
            }
        };

        $problem = new ProblemDetails($exception, '/x', false);

        self::assertSame('https://example.com/probs/out-of-credit', $problem->type());
        self::assertSame(422, $problem->status());
    }

    public function testHeadersAlwaysIncludeProblemJsonContentType(): void
    {
        $problem = new ProblemDetails(new NotFoundHttpException('x'), '/', false);

        self::assertSame('application/problem+json', $problem->headers()['Content-Type']);
    }

    public function testNonFiveHundredHttpExceptionInDebugHasNoAllowHeader(): void
    {
        $problem = new ProblemDetails(new BadRequestHttpException('nope'), '/x', true);

        self::assertArrayNotHasKey('Allow', $problem->headers());
    }

    public function testCustomHttpExceptionHeadersAreMergedIntoResponseHeaders(): void
    {
        $exception = new class ('rate limit', 429) extends HttpException {
            public function __construct(string $message, int $status)
            {
                parent::__construct($status, $message, 'about:blank');
            }

            public function headers(): array
            {
                return ['Retry-After' => '60'];
            }
        };

        $problem = new ProblemDetails($exception, '/x', false);

        $headers = $problem->headers();
        self::assertSame('application/problem+json', $headers['Content-Type']);
        self::assertSame('60', $headers['Retry-After']);
    }

    public function testToArrayMirrorsIndividualAccessors(): void
    {
        $problem = new ProblemDetails(
            new NotFoundHttpException('missing'),
            '/missing',
            false,
        );

        $body = $problem->toArray();

        self::assertSame('about:blank', $body['type']);
        self::assertSame('Not Found', $body['title']);
        self::assertSame(404, $body['status']);
        self::assertSame('missing', $body['detail']);
        self::assertSame('/missing', $body['instance']);
        self::assertArrayNotHasKey('trace', $body);
        self::assertArrayNotHasKey('errors', $body);
    }

    public function testToArrayIncludesTraceForFiveHundredInDebug(): void
    {
        $problem = new ProblemDetails(new RuntimeException('boom'), '/crash', true);

        $body = $problem->toArray();

        self::assertArrayHasKey('trace', $body);
        self::assertIsArray($body['trace']);
    }

    public function testToResponseBuildsRfc7807ResponseWithCorrectStatus(): void
    {
        $problem = new ProblemDetails(
            new NotFoundHttpException('missing'),
            '/missing',
            false,
        );

        $response = $problem->toResponse();

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(404, $response->status);
        self::assertSame('application/problem+json', $response->headers['Content-Type']);

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame('Not Found', $body['title']);
        self::assertSame(404, $body['status']);
        self::assertSame('missing', $body['detail']);
        self::assertSame('/missing', $body['instance']);
    }

    public function testToResponseIncludesAllowHeaderForFourHundredFive(): void
    {
        $problem = new ProblemDetails(
            new MethodNotAllowedHttpException('blocked', null, ['GET', 'POST']),
            '/x',
            false,
        );

        $response = $problem->toResponse();

        self::assertSame(405, $response->status);
        self::assertSame('GET, POST', $response->headers['Allow'] ?? null);
    }

    public function testInstanceIsPreservedAsProvided(): void
    {
        $problem = new ProblemDetails(new RuntimeException('x'), '/api/orders/42', false);

        self::assertSame('/api/orders/42', $problem->toArray()['instance']);
    }

    public function testHttpExceptionInProductionWithEmptyMessageUsesTitleAsDetail(): void
    {
        $problem = new ProblemDetails(new NotFoundHttpException(''), '/', false);

        self::assertSame('Not Found', $problem->detail());
    }
}
