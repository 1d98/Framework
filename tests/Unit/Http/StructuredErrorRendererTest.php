<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Exception\InternalServerErrorHttpException;
use Framework\Http\Request\Request;
use Framework\Http\StructuredErrorRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(StructuredErrorRenderer::class)]
final class StructuredErrorRendererTest extends TestCase
{
    public function testDefaultRendererIncludesRequestId(): void
    {
        $renderer = new StructuredErrorRenderer();
        $request = (new Request('GET', '/users/42'))->withId('abc123');

        $response = $renderer->render(new BadRequestHttpException('bad'), $request);

        self::assertSame('abc123', $response->headers['X-Request-Id'] ?? null);
        $body = $this->decodeBody($response->body);
        self::assertSame('abc123', $body['requestId']);
    }

    public function testDefaultRendererIncludesTraceId(): void
    {
        $renderer = new StructuredErrorRenderer();
        $request = new Request('GET', '/users/42');

        $response = $renderer->render(new BadRequestHttpException('bad'), $request);

        $body = $this->decodeBody($response->body);
        self::assertIsString($body['traceId']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $body['traceId']);
        self::assertArrayHasKey('traceparent', $response->headers);
    }

    public function testHonoursIncomingTraceparent(): void
    {
        $renderer = new StructuredErrorRenderer();
        $request = new Request(
            method: 'GET',
            path: '/users/42',
            headers: [
                'traceparent' => '00-aaaabbbbccccddddeeeeffffaaaabbbb-1111222233334444-01',
            ],
        );

        $response = $renderer->render(new BadRequestHttpException('bad'), $request);

        $body = $this->decodeBody($response->body);
        self::assertSame('aaaabbbbccccddddeeeeffffaaaabbbb', $body['traceId']);
    }

    public function testTypeFieldHiddenByDefault(): void
    {
        $renderer = new StructuredErrorRenderer();
        $request = new Request('GET', '/x');

        $response = $renderer->render(new BadRequestHttpException('bad'), $request);

        $body = $this->decodeBody($response->body);
        self::assertArrayNotHasKey('type', $body);
    }

    public function testTypeFieldShownWhenExposeTypeTrue(): void
    {
        $renderer = new StructuredErrorRenderer(exposeType: true);
        $request = new Request('GET', '/x');

        $response = $renderer->render(new BadRequestHttpException('bad'), $request);

        $body = $this->decodeBody($response->body);
        self::assertSame('about:blank', $body['type']);
    }

    public function testRedactTraceSuppressesStackFramesInDebug(): void
    {
        $renderer = new StructuredErrorRenderer(debug: true);
        $request = new Request('GET', '/x');

        $response = $renderer->render(new RuntimeException('boom'), $request);

        $body = $this->decodeBody($response->body);
        self::assertArrayNotHasKey('trace', $body, 'redactTrace default true must suppress trace field');
    }

    public function testRedactTraceExplicitlyFalseShowsTraceInDebug(): void
    {
        $renderer = new StructuredErrorRenderer(debug: true, redactTrace: false);
        $request = new Request('GET', '/x');

        $response = $renderer->render(new RuntimeException('boom'), $request);

        $body = $this->decodeBody($response->body);
        self::assertArrayHasKey('trace', $body);
        self::assertIsArray($body['trace']);
        self::assertNotEmpty($body['trace']);
    }

    public function testIncludeRequestIdFalse(): void
    {
        $renderer = new StructuredErrorRenderer(includeRequestId: false);
        $request = (new Request('GET', '/x'))->withId('should-not-appear');

        $response = $renderer->render(new BadRequestHttpException('bad'), $request);

        self::assertArrayNotHasKey('X-Request-Id', $response->headers);
        $body = $this->decodeBody($response->body);
        self::assertArrayNotHasKey('requestId', $body);
    }

    public function testIncludeTraceIdFalse(): void
    {
        $renderer = new StructuredErrorRenderer(includeTraceId: false);
        $request = new Request('GET', '/x');

        $response = $renderer->render(new BadRequestHttpException('bad'), $request);

        self::assertArrayNotHasKey('traceparent', $response->headers);
        $body = $this->decodeBody($response->body);
        self::assertArrayNotHasKey('traceId', $body);
    }

    public function testContentTypeIsProblemJson(): void
    {
        $renderer = new StructuredErrorRenderer();
        $request = new Request('GET', '/x');

        $response = $renderer->render(new BadRequestHttpException('bad'), $request);

        self::assertSame('application/problem+json', $response->headers['Content-Type']);
    }

    public function testStatusCodeMatchesException(): void
    {
        $renderer = new StructuredErrorRenderer();
        $request = new Request('GET', '/x');

        $response = $renderer->render(new BadRequestHttpException('bad'), $request);
        self::assertSame(400, $response->status);

        $response = $renderer->render(new InternalServerErrorHttpException('boom'), $request);
        self::assertSame(500, $response->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(string $body): array
    {
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
