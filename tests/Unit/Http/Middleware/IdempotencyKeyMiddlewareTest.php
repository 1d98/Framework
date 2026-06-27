<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Middleware;

use Framework\Http\Cookie\Cookie;
use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Exception\ConflictHttpException;
use Framework\Http\Exception\UnprocessableEntityHttpException;
use Framework\Http\Idempotency\FilesystemIdempotencyStore;
use Framework\Http\Idempotency\IdempotencyEntry;
use Framework\Http\Idempotency\InMemoryIdempotencyStore;
use Framework\Http\Middleware\IdempotencyKeyMiddleware;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Response\ResponseInterface;
use Framework\Http\Response\StreamedResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdempotencyKeyMiddleware::class)]
final class IdempotencyKeyMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        InMemoryIdempotencyStore::reset();
    }

    protected function tearDown(): void
    {
        InMemoryIdempotencyStore::reset();
    }

    public function testGetPassesThroughWithoutKey(): void
    {
        $store = new InMemoryIdempotencyStore();
        $middleware = new IdempotencyKeyMiddleware(store: $store);
        $request = new Request('GET', '/users');

        $response = $middleware->process(
            $request,
            static fn(): ResponseInterface => Response::text('ok'),
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->status);
        self::assertSame('ok', $response->body);
    }

    public function testPostWithoutKeyOnRequiredMethodReturns400(): void
    {
        $middleware = new IdempotencyKeyMiddleware();
        $request = new Request('POST', '/orders');

        $this->expectException(BadRequestHttpException::class);
        $middleware->process(
            $request,
            static fn(): ResponseInterface => Response::text('should not run'),
        );
    }

    public function testPatchWithoutKeyPassesThrough(): void
    {
        $middleware = new IdempotencyKeyMiddleware();
        $request = new Request('PATCH', '/orders/1');

        $response = $middleware->process(
            $request,
            static fn(): ResponseInterface => Response::text('ok'),
        );
        self::assertSame(200, $response->status);
    }

    public function testFirstRequestRunsHandlerAndStores(): void
    {
        $store = new InMemoryIdempotencyStore();
        $middleware = new IdempotencyKeyMiddleware(store: $store);
        $request = new Request(
            method: 'POST',
            path: '/orders',
            headers: ['idempotency-key' => 'K-001'],
            body: '{"item":"widget"}',
        );

        $handlerCalls = 0;
        $response = $middleware->process(
            $request,
            function (Request $r) use (&$handlerCalls): Response {
                $handlerCalls++;
                return Response::json(['id' => 99], 201);
            },
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(1, $handlerCalls);
        self::assertSame(201, $response->status);
        self::assertNotNull($store->get('K-001', 'POST', '/orders', hash('sha256', '{"item":"widget"}')));
    }

    public function testRetryReplaysCachedResponse(): void
    {
        $store = new InMemoryIdempotencyStore();
        $middleware = new IdempotencyKeyMiddleware(store: $store);
        $body = '{"item":"widget"}';
        $request = new Request(
            method: 'POST',
            path: '/orders',
            headers: ['idempotency-key' => 'K-001'],
            body: $body,
        );

        $first = $middleware->process(
            $request,
            static fn(): ResponseInterface => Response::json(['id' => 99], 201),
        );

        $handlerCalls = 0;
        $second = $middleware->process(
            $request,
            function (Request $r) use (&$handlerCalls): Response {
                $handlerCalls++;
                return Response::json(['id' => 100], 201);
            },
        );

        self::assertInstanceOf(Response::class, $first);
        self::assertInstanceOf(Response::class, $second);
        self::assertSame(0, $handlerCalls, 'Handler must not run on replay');
        self::assertSame($first->status, $second->status);
        self::assertSame($first->body, $second->body);
        self::assertSame('true', $second->headers['Idempotency-Replayed']);
    }

    public function testRetryWithDifferentBodyReturns422(): void
    {
        $store = new InMemoryIdempotencyStore();
        $middleware = new IdempotencyKeyMiddleware(store: $store);
        $first = new Request(
            method: 'POST',
            path: '/orders',
            headers: ['idempotency-key' => 'K-001'],
            body: '{"item":"widget"}',
        );
        $middleware->process($first, static fn(): ResponseInterface => Response::json(['id' => 99], 201));

        $retryWithDifferentBody = new Request(
            method: 'POST',
            path: '/orders',
            headers: ['idempotency-key' => 'K-001'],
            body: '{"item":"different"}',
        );

        $this->expectException(UnprocessableEntityHttpException::class);
        $middleware->process(
            $retryWithDifferentBody,
            static fn(): Response => self::fail('Handler must not run on conflict'),
        );
    }

    public function testRetryWithDifferentMethodReturns422(): void
    {
        $store = new InMemoryIdempotencyStore();
        $middleware = new IdempotencyKeyMiddleware(store: $store);
        $first = new Request(
            method: 'POST',
            path: '/orders',
            headers: ['idempotency-key' => 'K-001'],
            body: '{}',
        );
        $middleware->process($first, static fn(): ResponseInterface => Response::text('ok'));

        $retryAsDelete = new Request(
            method: 'DELETE',
            path: '/orders',
            headers: ['idempotency-key' => 'K-001'],
            body: '{}',
        );

        $this->expectException(UnprocessableEntityHttpException::class);
        $middleware->process(
            $retryAsDelete,
            static fn(): Response => self::fail('Handler must not run on conflict'),
        );
    }

    public function testEmptyKeyAfterTrimReturns400(): void
    {
        $middleware = new IdempotencyKeyMiddleware();
        $request = new Request(
            method: 'POST',
            path: '/orders',
            headers: ['idempotency-key' => '   '],
        );

        $this->expectException(BadRequestHttpException::class);
        $middleware->process(
            $request,
            static fn(): ResponseInterface => Response::text('ok'),
        );
    }

    public function testOverlongKeyReturns400(): void
    {
        $middleware = new IdempotencyKeyMiddleware(maxKeyLength: 16);
        $request = new Request(
            method: 'POST',
            path: '/orders',
            headers: ['idempotency-key' => str_repeat('a', 100)],
        );

        $this->expectException(BadRequestHttpException::class);
        $middleware->process(
            $request,
            static fn(): ResponseInterface => Response::text('ok'),
        );
    }

    public function testControlByteInKeyReturns400(): void
    {
        $middleware = new IdempotencyKeyMiddleware();
        $request = new Request(
            method: 'POST',
            path: '/orders',
            headers: ['idempotency-key' => "abc\x00def"],
        );

        $this->expectException(BadRequestHttpException::class);
        $middleware->process(
            $request,
            static fn(): ResponseInterface => Response::text('ok'),
        );
    }

    public function testConcurrentTryReserveFailureReturns409(): void
    {
        // A store that always refuses tryReserve simulates a
        // concurrent request in flight.
        $store = new class implements \Framework\Http\Idempotency\IdempotencyStoreInterface {
            public function get(string $key, string $method, string $path, string $bodyHash): ?IdempotencyEntry
            {
                return null;
            }
            public function put(string $key, string $method, string $path, string $bodyHash, IdempotencyEntry $entry): void {}
            public function tryReserve(string $key, string $method, string $path, string $bodyHash): bool
            {
                return false;
            }
            public function sweep(int $olderThanSeconds): int
            {
                return 0;
            }
            public function forget(string $key): void {}
        };

        $middleware = new IdempotencyKeyMiddleware(store: $store);
        $request = new Request(
            method: 'POST',
            path: '/orders',
            headers: ['idempotency-key' => 'K-001'],
        );

        $this->expectException(ConflictHttpException::class);
        $middleware->process(
            $request,
            static fn(): Response => self::fail('Handler must not run when reservation fails'),
        );
    }

    public function testFilesystemStoreRoundTrip(): void
    {
        $dir = sys_get_temp_dir() . '/fw-idem-mw-' . bin2hex(random_bytes(4));
        try {
            $store = new FilesystemIdempotencyStore($dir);
            $middleware = new IdempotencyKeyMiddleware(store: $store);

            $request = new Request(
                method: 'POST',
                path: '/orders',
                headers: ['idempotency-key' => 'K-FS'],
                body: 'first',
            );
            $first = $middleware->process(
                $request,
                static fn(): ResponseInterface => Response::text('first-response'),
            );

            $second = $middleware->process(
                $request,
                static fn(): Response => self::fail('Handler must not run on replay'),
            );

            self::assertInstanceOf(Response::class, $first);
            self::assertInstanceOf(Response::class, $second);
            self::assertSame($first->status, $second->status);
            self::assertSame($first->body, $second->body);
            self::assertSame('true', $second->headers['Idempotency-Replayed']);
        } finally {
            \Framework\Filesystem\AtomicFilesystem::removeTree($dir);
        }
    }

    public function testFilesystemStoreRejectsBodyHashMismatch(): void
    {
        $dir = sys_get_temp_dir() . '/fw-idem-mw-' . bin2hex(random_bytes(4));
        try {
            $store = new FilesystemIdempotencyStore($dir);
            $middleware = new IdempotencyKeyMiddleware(store: $store);

            $first = new Request(
                method: 'POST',
                path: '/orders',
                headers: ['idempotency-key' => 'K-FS'],
                body: 'first',
            );
            $middleware->process($first, static fn(): ResponseInterface => Response::text('r1'));

            $retry = new Request(
                method: 'POST',
                path: '/orders',
                headers: ['idempotency-key' => 'K-FS'],
                body: 'second',
            );

            $this->expectException(UnprocessableEntityHttpException::class);
            $middleware->process(
                $retry,
                static fn(): Response => self::fail('Handler must not run on conflict'),
            );
        } finally {
            \Framework\Filesystem\AtomicFilesystem::removeTree($dir);
        }
    }

    public function testReplayPreservesCookies(): void
    {
        $store = new InMemoryIdempotencyStore();
        $middleware = new IdempotencyKeyMiddleware(store: $store);
        $request = new Request(
            method: 'POST',
            path: '/login',
            headers: ['idempotency-key' => 'K-LOGIN'],
            body: '{"u":"a","p":"b"}',
        );

        $first = $middleware->process(
            $request,
            static fn(): ResponseInterface => Response::empty(204)->withCookie(new Cookie('session', 'token-xyz')),
        );

        $second = $middleware->process(
            $request,
            static fn(): Response => self::fail('Handler must not run on replay'),
        );

        self::assertInstanceOf(Response::class, $second);
        $cookies = $second->cookies;
        self::assertCount(1, $cookies);
        self::assertSame('session', $cookies[0]->name);
        self::assertSame('token-xyz', $cookies[0]->value);
    }

    public function testCustomMethodsArray(): void
    {
        $middleware = new IdempotencyKeyMiddleware(methods: ['POST'], requiredOn: ['POST']);
        // GET should pass through even when it has an Idempotency-Key
        $request = new Request(
            method: 'GET',
            path: '/x',
            headers: ['idempotency-key' => 'K-001'],
        );
        $response = $middleware->process(
            $request,
            static fn(): ResponseInterface => Response::text('ok'),
        );
        self::assertSame(200, $response->status);
    }

    public function testConstructorRejectsInvalidMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IdempotencyKeyMiddleware(methods: ['BREW']);
    }

    public function testConstructorRejectsInvalidTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IdempotencyKeyMiddleware(ttl: 0);
    }

    public function testStreamedResponsePassesThroughAndReleasesReservation(): void
    {
        // When a handler returns a StreamedResponse the middleware cannot
        // serialise it for replay — the body is produced at send() time.
        // The contract: pass the streamed response through unchanged AND
        // release the reservation so the next request with the same key
        // can re-execute the handler (instead of being rejected by the
        // held reservation in tryReserve).
        $store = new InMemoryIdempotencyStore();
        $middleware = new IdempotencyKeyMiddleware(store: $store);
        $request = new Request(
            method: 'POST',
            path: '/events',
            headers: ['idempotency-key' => 'K-SSE'],
            body: '{}',
        );

        $emitted = false;
        $streamed = StreamedResponse::sse(static function ($_stream) use (&$emitted): void {
            $emitted = true;
        });

        $response = $middleware->process(
            $request,
            static fn(): ResponseInterface => $streamed,
        );

        self::assertSame($streamed, $response, 'StreamedResponse must be passed through unchanged');
        self::assertFalse($emitted, 'Emitters must not run inside the middleware — only at send() time');

        // The reservation must have been released so the next request
        // can re-execute (not 409 Conflict).
        $handlerCalls = 0;
        $second = $middleware->process(
            $request,
            static function () use (&$handlerCalls): Response {
                $handlerCalls++;
                return Response::json(['replayed' => false]);
            },
        );
        self::assertSame(1, $handlerCalls, 'Second request must re-execute the handler (reservation was released)');
        self::assertInstanceOf(Response::class, $second);
        self::assertFalse($second->headers['Idempotency-Replayed'] ?? false);
    }

    public function testForgetIsInvokedOnStreamingResponse(): void
    {
        // Spy store: capture forget() calls and verify the streaming-response
        // branch of the middleware invokes IdempotencyStoreInterface::forget().
        $store = new \Framework\Tests\Unit\Http\Middleware\SpyIdempotencyStore();

        $middleware = new IdempotencyKeyMiddleware(store: $store);
        $request = new Request(
            method: 'POST',
            path: '/stream',
            headers: ['idempotency-key' => 'K-FORGET'],
        );

        $middleware->process(
            $request,
            static fn(): ResponseInterface => StreamedResponse::ndjson(static function (): void {}),
        );

        self::assertSame(['K-FORGET'], $store->forgotten);
    }
}

/**
 * Spy {@see \Framework\Http\Idempotency\IdempotencyStoreInterface} used by
 * {@see IdempotencyKeyMiddlewareTest::testForgetIsInvokedOnStreamingResponse()}
 * to capture `forget()` invocations. Kept as a named class (not an anonymous
 * class) so PHPStan recognises the `$forgotten` property as read by the
 * outer test.
 */
final class SpyIdempotencyStore implements \Framework\Http\Idempotency\IdempotencyStoreInterface
{
    /** @var list<string> */
    public array $forgotten = [];

    public function get(string $key, string $method, string $path, string $bodyHash): ?IdempotencyEntry
    {
        return null;
    }

    public function put(string $key, string $method, string $path, string $bodyHash, IdempotencyEntry $entry): void
    {
    }

    public function tryReserve(string $key, string $method, string $path, string $bodyHash): bool
    {
        return true;
    }

    public function sweep(int $olderThanSeconds): int
    {
        return 0;
    }

    public function forget(string $key): void
    {
        $this->forgotten[] = $key;
    }
}
