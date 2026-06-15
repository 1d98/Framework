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
            static fn(): Response => Response::text('ok'),
        );

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
            static fn(): Response => Response::text('should not run'),
        );
    }

    public function testPatchWithoutKeyPassesThrough(): void
    {
        $middleware = new IdempotencyKeyMiddleware();
        $request = new Request('PATCH', '/orders/1');

        $response = $middleware->process(
            $request,
            static fn(): Response => Response::text('ok'),
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
            static fn(): Response => Response::json(['id' => 99], 201),
        );

        $handlerCalls = 0;
        $second = $middleware->process(
            $request,
            function (Request $r) use (&$handlerCalls): Response {
                $handlerCalls++;
                return Response::json(['id' => 100], 201);
            },
        );

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
        $middleware->process($first, static fn(): Response => Response::json(['id' => 99], 201));

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
        $middleware->process($first, static fn(): Response => Response::text('ok'));

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
            static fn(): Response => Response::text('ok'),
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
            static fn(): Response => Response::text('ok'),
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
            static fn(): Response => Response::text('ok'),
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
                static fn(): Response => Response::text('first-response'),
            );

            $second = $middleware->process(
                $request,
                static fn(): Response => self::fail('Handler must not run on replay'),
            );

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
            $middleware->process($first, static fn(): Response => Response::text('r1'));

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
            static fn(): Response => Response::empty(204)->withCookie(new Cookie('session', 'token-xyz')),
        );

        $second = $middleware->process(
            $request,
            static fn(): Response => self::fail('Handler must not run on replay'),
        );

        self::assertCount(1, $second->cookies());
        self::assertSame('session', $second->cookies()[0]->name);
        self::assertSame('token-xyz', $second->cookies()[0]->value);
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
            static fn(): Response => Response::text('ok'),
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
}
