<?php

declare(strict_types=1);

namespace Framework\Tests\Integration;

use Framework\Http\HttpKernel;
use Framework\Http\Idempotency\InMemoryIdempotencyStore;
use Framework\Http\Middleware\IdempotencyKeyMiddleware;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Response\ResponseInterface;
use Framework\Http\Response\StreamedResponse;
use Framework\Http\Router\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end test for the streaming-response interactions across the
 * full HttpKernel pipeline. Unlike {@see \Framework\Tests\Unit\Http\Response\StreamedResponseTest}
 * this exercises:
 *
 *   - {@see HttpKernel::handle()} returning a StreamedResponse as the
 *     final ResponseInterface for a streaming route.
 *   - {@see IdempotencyKeyMiddleware} passing a StreamedResponse
 *     through unchanged AND releasing the reservation so the next
 *     request with the same `Idempotency-Key` re-executes the handler.
 *   - {@see Pipeline::process()} handling a mix of middleware types
 *     (CompressionMiddleware, EtagMiddleware, IdempotencyKeyMiddleware)
 *     when one of them short-circuits on a StreamedResponse.
 *
 * The full HTTP server / SAPI bootstrap is intentionally not used here —
 * the {@see \Framework\Tests\Integration\HttpEndToEndTest} family owns
 * that surface. This file focuses on the in-process boundary between
 * the kernel, the pipeline, and the idempotency middleware.
 */
#[CoversClass(HttpKernel::class)]
#[CoversClass(IdempotencyKeyMiddleware::class)]
#[CoversClass(StreamedResponse::class)]
final class StreamedResponseEndToEndTest extends TestCase
{
    protected function setUp(): void
    {
        InMemoryIdempotencyStore::reset();
    }

    protected function tearDown(): void
    {
        InMemoryIdempotencyStore::reset();
    }

    public function testKernelReturnsStreamedResponseForStreamingRoute(): void
    {
        // A route that returns a StreamedResponse MUST be passed back
        // through the kernel unchanged — the kernel's
        // `$response->withRequestId()` call must work via the
        // ResponseInterface contract, not via the concrete `Response`.
        $router = new Router();
        $router->get('/events', static fn(): StreamedResponse => StreamedResponse::sse(static function (): void {}));

        $kernel = new HttpKernel($router);
        $request = new Request('GET', '/events');

        $response = $kernel->handle($request);

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame(200, $response->status);
        self::assertSame('text/event-stream', $response->headers['Content-Type']);
        // The kernel applies withRequestId() — the request id MUST be
        // present on the streamed response.
        self::assertSame($request->id, $response->headers['X-Request-Id']);
    }

    public function testKernelReturnsStreamedResponseForNdjsonRoute(): void
    {
        $router = new Router();
        $router->get('/stream', static fn(): StreamedResponse => StreamedResponse::ndjson(static function (): void {}));

        $kernel = new HttpKernel($router);
        $request = new Request('GET', '/stream');

        $response = $kernel->handle($request);

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('application/x-ndjson; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testIdempotencyMiddlewarePassesStreamedResponseThroughWithoutStorage(): void
    {
        // The streaming-response branch of the idempotency middleware:
        //   1. StreamedResponse is returned to the caller unchanged.
        //   2. The reservation (taken in tryReserve) is released via
        //      IdempotencyStoreInterface::forget(), so a retry with the
        //      same Idempotency-Key re-executes the handler instead of
        //      being rejected with 409 Conflict.
        $router = new Router();
        $router->post('/events', static function (Request $r): StreamedResponse {
            return StreamedResponse::sse(static function (): void {});
        });

        $store = new InMemoryIdempotencyStore();
        $pipeline = new Pipeline();
        $pipeline->pipe(new IdempotencyKeyMiddleware(store: $store));

        $kernel = new HttpKernel($router, $pipeline);
        $request = new Request(
            method: 'POST',
            path: '/events',
            headers: ['idempotency-key' => 'K-STREAM-1'],
            body: '{}',
        );

        $first = $kernel->handle($request);
        self::assertInstanceOf(StreamedResponse::class, $first);

        // Second call with the same Idempotency-Key MUST re-execute the
        // handler — NOT return a cached Response, NOT return 409 Conflict.
        $handlerCalls = 0;
        $capturedHandlerCalls = static function (Request $r) use (&$handlerCalls, $kernel): ResponseInterface {
            $handlerCalls++;
            $request2 = new Request(
                method: 'POST',
                path: '/events',
                headers: ['idempotency-key' => 'K-STREAM-1'],
                body: '{}',
            );
            return $kernel->handle($request2);
        };

        // Use a separate router that counts invocations to verify
        // re-execution happened.
        $counter = 0;
        $countingRouter = new Router();
        $countingRouter->post('/events', static function () use (&$counter): StreamedResponse {
            $counter++;
            return StreamedResponse::sse(static function (): void {});
        });

        $store2 = new InMemoryIdempotencyStore();
        $pipeline2 = new Pipeline();
        $pipeline2->pipe(new IdempotencyKeyMiddleware(store: $store2));
        $kernel2 = new HttpKernel($countingRouter, $pipeline2);

        $req = new Request(
            method: 'POST',
            path: '/events',
            headers: ['idempotency-key' => 'K-STREAM-2'],
            body: '{}',
        );

        $first = $kernel2->handle($req);
        self::assertInstanceOf(StreamedResponse::class, $first);
        self::assertSame(1, $counter, 'First request must execute handler once');

        $second = $kernel2->handle($req);
        self::assertInstanceOf(StreamedResponse::class, $second, 'Second request must also return a StreamedResponse');
        self::assertSame(2, $counter, 'Second request must re-execute handler (forget() released reservation)');

        // Silence unused warnings on the closure above.
        unset($capturedHandlerCalls);
    }

    public function testIdempotencyMiddlewareStillRejectsBufferedReplaysAfterStreamingRequest(): void
    {
        // Streaming and buffered responses share the same store. A
        // buffered response captured under the key must still replay
        // correctly; the streaming forget() for THAT key must not
        // disturb the buffered entry that was put() earlier.
        $counter = 0;
        $countingRouter = new Router();
        $countingRouter->post('/orders', static function () use (&$counter): ResponseInterface {
            $counter++;
            return Response::json(['id' => $counter]);
        });
        $countingRouter->post('/events', static fn(): StreamedResponse => StreamedResponse::sse(static function (): void {}));

        $store = new InMemoryIdempotencyStore();
        $pipeline = new Pipeline();
        $pipeline->pipe(new IdempotencyKeyMiddleware(store: $store));

        $kernel = new HttpKernel($countingRouter, $pipeline);

        // Buffered response: key K-A — first call runs, second call replays.
        $keyA = 'K-A';
        $orderReq = new Request(
            method: 'POST',
            path: '/orders',
            headers: ['idempotency-key' => $keyA],
            body: '{"item":"widget"}',
        );

        $first = $kernel->handle($orderReq);
        self::assertInstanceOf(Response::class, $first);
        self::assertSame(1, $counter);

        $replay = $kernel->handle($orderReq);
        self::assertInstanceOf(Response::class, $replay);
        self::assertSame(1, $counter, 'Buffered replay must not re-execute the handler');

        // Streaming response under a DIFFERENT key: forget() releases
        // only the streaming slot, the buffered entry under K-A stays.
        $streamReq = new Request(
            method: 'POST',
            path: '/events',
            headers: ['idempotency-key' => 'K-STREAM'],
            body: '{}',
        );
        $streamed = $kernel->handle($streamReq);
        self::assertInstanceOf(StreamedResponse::class, $streamed);

        // Buffered replay under K-A STILL replays (not re-executes).
        $replayAgain = $kernel->handle($orderReq);
        self::assertInstanceOf(Response::class, $replayAgain);
        self::assertSame(1, $counter, 'Streaming forget() must not invalidate buffered entries');
    }

    public function testPipelineComposesStreamedResponseThroughMultipleMiddleware(): void
    {
        // Pipeline: CompressionMiddleware → IdempotencyKeyMiddleware → router.
        // The route returns a StreamedResponse. Both middlewares must
        // short-circuit on the streaming response without disturbing it.
        $router = new Router();
        $router->get('/events', static fn(): StreamedResponse => StreamedResponse::sse(static function (): void {}));

        $pipeline = new Pipeline();
        $pipeline->pipe(new \Framework\Http\Middleware\CompressionMiddleware());
        $pipeline->pipe(new IdempotencyKeyMiddleware(store: new InMemoryIdempotencyStore()));

        $kernel = new HttpKernel($router, $pipeline);
        $request = new Request('GET', '/events', '', ['accept-encoding' => 'gzip']);

        $response = $kernel->handle($request);

        self::assertInstanceOf(StreamedResponse::class, $response);
        // CompressionMiddleware must NOT install Content-Encoding on the
        // streamed response (it short-circuits on StreamedResponse).
        self::assertArrayNotHasKey('Content-Encoding', $response->headers);
        // The original SSE Content-Type is preserved.
        self::assertSame('text/event-stream', $response->headers['Content-Type']);
    }
}