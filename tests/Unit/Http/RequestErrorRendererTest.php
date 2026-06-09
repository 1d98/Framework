<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Exception\InternalServerErrorHttpException;
use Framework\Http\Exception\MethodNotAllowedHttpException;
use Framework\Http\Exception\NotFoundHttpException;
use Framework\Http\Request\Request;
use Framework\Http\RequestErrorRenderer;
use Framework\Validation\ValidationError;
use Framework\Validation\ValidationErrorCollection;
use Framework\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RequestErrorRenderer::class)]
final class RequestErrorRendererTest extends TestCase
{
    public function testRendersHttpExceptionAsRfc7807(): void
    {
        $renderer = new RequestErrorRenderer(debug: false);
        $request = new Request('GET', '/missing');

        $response = $renderer->render(new NotFoundHttpException('No route matches'), $request);

        self::assertSame(404, $response->status);
        self::assertSame('application/problem+json', $response->headers['Content-Type'] ?? null);

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame(404, $body['status']);
        self::assertSame('Not Found', $body['title']);
        self::assertSame('/missing', $body['instance']);
        self::assertSame('No route matches', $body['detail']);
    }

    public function testRendersValidationExceptionAs422WithErrors(): void
    {
        $renderer = new RequestErrorRenderer(debug: false);
        $request = new Request('POST', '/api/v1/users');

        $errors = new ValidationErrorCollection([
            new ValidationError('email', 'required', 'Field is required'),
            new ValidationError('age', 'min', 'Must be at least 18', 5),
        ]);

        $response = $renderer->render(new ValidationException($errors), $request);

        self::assertSame(422, $response->status);
        self::assertSame('application/problem+json', $response->headers['Content-Type'] ?? null);

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame(422, $body['status']);
        self::assertSame('Unprocessable Entity', $body['title']);
        self::assertArrayHasKey('errors', $body);
        self::assertIsArray($body['errors']);
        self::assertCount(2, $body['errors']);
    }

    public function testRendersGenericThrowableAs500InProduction(): void
    {
        $renderer = new RequestErrorRenderer(debug: false);
        $request = new Request('GET', '/crash');

        $response = $renderer->render(new RuntimeException('database connection lost'), $request);

        self::assertSame(500, $response->status);
        self::assertSame('application/problem+json', $response->headers['Content-Type'] ?? null);

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame(500, $body['status']);
        self::assertSame('Internal Server Error', $body['title']);
        self::assertSame('Internal Server Error', $body['detail']);
        self::assertArrayNotHasKey('trace', $body);
    }

    public function testRendersGenericThrowableWithMessageAndTraceInDebug(): void
    {
        $renderer = new RequestErrorRenderer(debug: true);
        $request = new Request('GET', '/crash');

        $response = $renderer->render(new RuntimeException('database connection lost'), $request);

        self::assertSame(500, $response->status);
        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame('database connection lost', $body['detail']);
        self::assertArrayHasKey('trace', $body);
        self::assertIsArray($body['trace']);
        self::assertNotEmpty($body['trace']);
    }

    public function testRendersHttpExceptionWithoutTraceInDebug(): void
    {
        $renderer = new RequestErrorRenderer(debug: true);
        $request = new Request('GET', '/bad');

        $response = $renderer->render(new BadRequestHttpException('email is invalid'), $request);

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame('email is invalid', $body['detail']);
        self::assertArrayNotHasKey('trace', $body);
    }

    public function testAttachesRequestIdFromRequest(): void
    {
        $renderer = new RequestErrorRenderer(debug: false);
        $request = new Request('GET', '/x', '', [], '', null, null, null, [], null, null, null, 'corr-id-renderer');

        $response = $renderer->render(new NotFoundHttpException('missing'), $request);

        self::assertSame('corr-id-renderer', $response->headers['X-Request-Id'] ?? null);
    }

    public function testAttachesRequestIdOnAllBranches(): void
    {
        $renderer = new RequestErrorRenderer(debug: false);

        $httpExcReq = new Request('POST', '/bad', '', [], '', null, null, null, [], null, null, null, 'http-exc-id');
        $httpExcResponse = $renderer->render(new BadRequestHttpException('nope'), $httpExcReq);
        self::assertSame('http-exc-id', $httpExcResponse->headers['X-Request-Id'] ?? null);

        $genericReq = new Request('GET', '/crash', '', [], '', null, null, null, [], null, null, null, 'generic-id');
        $genericResponse = $renderer->render(new RuntimeException('boom'), $genericReq);
        self::assertSame('generic-id', $genericResponse->headers['X-Request-Id'] ?? null);

        $validationReq = new Request('POST', '/api/users', '', [], '', null, null, null, [], null, null, null, 'validation-id');
        $validationResponse = $renderer->render(
            new ValidationException(new ValidationErrorCollection()),
            $validationReq,
        );
        self::assertSame('validation-id', $validationResponse->headers['X-Request-Id'] ?? null);
    }

    public function testAttachesAllowHeaderForMethodNotAllowed(): void
    {
        $renderer = new RequestErrorRenderer(debug: false);
        $request = new Request('OPTIONS', '/api/users');

        $response = $renderer->render(
            new MethodNotAllowedHttpException('blocked', null, ['GET', 'POST'], ['Allow' => 'GET, POST']),
            $request,
        );

        self::assertSame(405, $response->status);
        self::assertSame('GET, POST', $response->headers['Allow'] ?? null);
    }

    public function testInstanceIsRequestPath(): void
    {
        $renderer = new RequestErrorRenderer(debug: false);
        $request = new Request('GET', '/api/orders/42');

        $response = $renderer->render(new NotFoundHttpException('not here'), $request);

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame('/api/orders/42', $body['instance']);
    }

    public function testFiveXxHttpExceptionKeepsStatus(): void
    {
        $renderer = new RequestErrorRenderer(debug: false);
        $request = new Request('GET', '/fail');

        $response = $renderer->render(new InternalServerErrorHttpException('database down'), $request);

        self::assertSame(500, $response->status);
        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame(500, $body['status']);
        self::assertSame('Internal Server Error', $body['title']);
    }

    public function testProblemJsonContentTypeAlwaysSet(): void
    {
        $renderer = new RequestErrorRenderer(debug: true);
        $request = new Request('GET', '/x');

        $response = $renderer->render(new RuntimeException('boom'), $request);

        self::assertSame('application/problem+json', $response->headers['Content-Type'] ?? null);
    }
}
