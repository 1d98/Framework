<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Middleware;

use Framework\Clock\FakeClock;
use Framework\Http\Exception\TooManyRequestsHttpException;
use Framework\Http\Middleware\RateLimitMiddleware;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(RateLimitMiddleware::class)]
final class RateLimitMiddlewareTest extends TestCase
{
    private const string IP_A = '203.0.113.10';
    private const string IP_B = '203.0.113.11';

    protected function setUp(): void
    {
        $this->resetBuckets();
    }

    protected function tearDown(): void
    {
        $this->resetBuckets();
    }

    public function testFirstRequestPasses(): void
    {
        $middleware = new RateLimitMiddleware();
        $request = $this->requestFromIp(self::IP_A);

        $response = $middleware->process($request, static fn(): Response => Response::empty(204));

        self::assertSame(204, $response->status);
    }

    public function testCapacityRequestsAllPassThenNextThrows429(): void
    {
        $clock = new FakeClock();
        $middleware = new RateLimitMiddleware(capacity: 3, refillPerSecond: 1.0, clock: $clock);
        $request = $this->requestFromIp(self::IP_A);

        for ($i = 0; $i < 3; $i++) {
            $response = $middleware->process($request, static fn(): Response => Response::empty(204));
            self::assertSame(204, $response->status, "Request #{$i} should pass");
        }

        $this->expectException(TooManyRequestsHttpException::class);
        $this->expectExceptionMessage('Rate limit exceeded');
        $middleware->process($request, static fn(): Response => Response::empty(204));
    }

    public function testExhaustedBucketThrowsTooManyRequestsHttpExceptionWith429Status(): void
    {
        $clock = new FakeClock();
        $middleware = new RateLimitMiddleware(capacity: 1, refillPerSecond: 1.0, clock: $clock);
        $request = $this->requestFromIp(self::IP_A);

        $middleware->process($request, static fn(): Response => Response::empty(204));

        try {
            $middleware->process($request, static fn(): Response => Response::empty(204));
            self::fail('Expected TooManyRequestsHttpException');
        } catch (TooManyRequestsHttpException $e) {
            self::assertSame(429, $e->statusCode);
        }
    }

    public function testTokensRefillOverTime(): void
    {
        $clock = new FakeClock(0.0);
        $middleware = new RateLimitMiddleware(capacity: 2, refillPerSecond: 1.0, clock: $clock);
        $request = $this->requestFromIp(self::IP_A);

        $middleware->process($request, static fn(): Response => Response::empty(204));
        $middleware->process($request, static fn(): Response => Response::empty(204));

        try {
            $middleware->process($request, static fn(): Response => Response::empty(204));
            self::fail('Bucket should be empty at t=0');
        } catch (TooManyRequestsHttpException) {
            // expected
        }

        $clock->advance(1.0);

        $response = $middleware->process($request, static fn(): Response => Response::empty(204));
        self::assertSame(204, $response->status, 'One token should have refilled after 1s');
    }

    public function testRefillIsCappedAtCapacity(): void
    {
        $clock = new FakeClock(0.0);
        $middleware = new RateLimitMiddleware(capacity: 3, refillPerSecond: 1.0, clock: $clock);
        $request = $this->requestFromIp(self::IP_A);

        $middleware->process($request, static fn(): Response => Response::empty(204));
        $clock->advance(100.0);

        $middleware->process($request, static fn(): Response => Response::empty(204));
        $clock->advance(100.0);

        $bucket = $this->bucketSnapshot(self::IP_A);
        self::assertSame(2.0, $bucket['tokens'], 'After 100s of idle + 1 consume, 1 token should be left (capacity 3 capped)');
    }

    public function testTwoDifferentIpsHaveIndependentBuckets(): void
    {
        $clock = new FakeClock();
        $middleware = new RateLimitMiddleware(capacity: 1, refillPerSecond: 1.0, clock: $clock);

        $this->withRemoteAddr(self::IP_A, function () use ($middleware): void {
            $middleware->process(new Request('GET', '/'), static fn(): Response => Response::empty(204));
        });

        $responseB = $this->withRemoteAddr(self::IP_B, static function () use ($middleware): Response {
            return $middleware->process(new Request('GET', '/'), static fn(): Response => Response::empty(204));
        });

        self::assertSame(204, $responseB->status, 'IP B should have its own bucket');

        $this->expectException(TooManyRequestsHttpException::class);
        $this->withRemoteAddr(self::IP_A, function () use ($middleware): void {
            $middleware->process(new Request('GET', '/'), static fn(): Response => Response::empty(204));
        });
    }

    public function testBucketStateIsProcessWideNotPerRequest(): void
    {
        $clock = new FakeClock();
        $middleware = new RateLimitMiddleware(capacity: 2, refillPerSecond: 1.0, clock: $clock);

        for ($i = 0; $i < 2; $i++) {
            $this->withRemoteAddr(self::IP_A, function () use ($middleware): void {
                $middleware->process(new Request('GET', '/'), static fn(): Response => Response::empty(204));
            });
        }

        $this->expectException(TooManyRequestsHttpException::class);
        $this->withRemoteAddr(self::IP_A, function () use ($middleware): void {
            $middleware->process(new Request('GET', '/'), static fn(): Response => Response::empty(204));
        });
    }

    public function testCustomKeyExtractorIsHonored(): void
    {
        $clock = new FakeClock();
        $middleware = new RateLimitMiddleware(
            capacity: 1,
            refillPerSecond: 1.0,
            clock: $clock,
            keyExtractor: static fn(Request $r): string => $r->header('X-User-Id') ?? 'anon',
        );

        $alice = new Request('GET', '/', '', ['x-user-id' => 'alice']);
        $bob = new Request('GET', '/', '', ['x-user-id' => 'bob']);

        $middleware->process($alice, static fn(): Response => Response::empty(204));

        $response = $middleware->process($bob, static fn(): Response => Response::empty(204));
        self::assertSame(204, $response->status, 'Bob has a separate bucket from Alice');

        $this->expectException(TooManyRequestsHttpException::class);
        $middleware->process($alice, static fn(): Response => Response::empty(204));
    }

    public function testKeyExtractorEmptyStringFallsBackToUnknown(): void
    {
        $clock = new FakeClock();
        $middleware = new RateLimitMiddleware(
            capacity: 1,
            refillPerSecond: 1.0,
            clock: $clock,
            keyExtractor: static fn(): string => '',
        );

        $request = $this->requestFromIp(self::IP_A);
        $middleware->process($request, static fn(): Response => Response::empty(204));

        $this->expectException(TooManyRequestsHttpException::class);
        $middleware->process($request, static fn(): Response => Response::empty(204));
    }

    public function testDefaultKeyExtractorFallsBackToUnknownWhenIpMissing(): void
    {
        $clock = new FakeClock();
        $middleware = new RateLimitMiddleware(capacity: 1, refillPerSecond: 1.0, clock: $clock);

        $request = new Request('GET', '/');
        $this->withRemoteAddr('', function () use ($middleware, $request): void {
            $middleware->process($request, static fn(): Response => Response::empty(204));
        });

        $this->expectException(TooManyRequestsHttpException::class);
        $this->withRemoteAddr('', function () use ($middleware, $request): void {
            $middleware->process($request, static fn(): Response => Response::empty(204));
        });
    }

    public function testClockInjectionAllowsFastTests(): void
    {
        $clock = new FakeClock(0.0);
        $middleware = new RateLimitMiddleware(
            capacity: 5,
            refillPerSecond: 10.0,
            clock: $clock,
        );
        $request = $this->requestFromIp(self::IP_A);

        for ($i = 0; $i < 5; $i++) {
            $middleware->process($request, static fn(): Response => Response::empty(204));
        }

        $clock->advance(0.1);
        $response = $middleware->process($request, static fn(): Response => Response::empty(204));
        self::assertSame(204, $response->status, 'A 0.1s advance at 10 t/s refills 1 token');
    }

    public function testConstructorRejectsNonPositiveCapacity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('capacity must be >= 1');
        new RateLimitMiddleware(capacity: 0);
    }

    public function testConstructorRejectsNonPositiveRefillRate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('refillPerSecond must be > 0');
        new RateLimitMiddleware(refillPerSecond: -1.0);
    }

    public function testProcessForwardsToNextHandlerOnSuccess(): void
    {
        $clock = new FakeClock();
        $middleware = new RateLimitMiddleware(capacity: 1, refillPerSecond: 1.0, clock: $clock);
        $request = $this->requestFromIp(self::IP_A);

        $response = $middleware->process(
            $request,
            static fn(Request $r): Response => Response::text('hello', 200),
        );

        self::assertSame(200, $response->status);
        self::assertSame('hello', $response->body);
    }

    public function testRequestIpIsHonoredForKeying(): void
    {
        $clock = new FakeClock();
        $middleware = new RateLimitMiddleware(capacity: 1, refillPerSecond: 1.0, clock: $clock);

        $this->withRemoteAddr(self::IP_A, function () use ($middleware): void {
            $middleware->process(new Request('GET', '/'), static fn(): Response => Response::empty(204));
        });

        $response = $this->withRemoteAddr(self::IP_B, static function () use ($middleware): Response {
            return $middleware->process(new Request('GET', '/'), static fn(): Response => Response::empty(204));
        });

        self::assertSame(204, $response->status);
    }

    private function requestFromIp(string $ip): Request
    {
        $_SERVER['REMOTE_ADDR'] = $ip;
        return new Request('GET', '/');
    }

    /**
     * Run a callback with `$_SERVER['REMOTE_ADDR']` pinned to the given
     * IP, then restore the previous value. Needed for tests where the
     * middleware reads the IP at process time, not at request
     * construction time.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withRemoteAddr(string $addr, callable $fn): mixed
    {
        $previous = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = $addr;
        try {
            return $fn();
        } finally {
            if ($previous === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous;
            }
        }
    }

    private function resetBuckets(): void
    {
        $ref = new ReflectionClass(RateLimitMiddleware::class);
        $prop = $ref->getProperty('buckets');
        $prop->setValue(null, []);
    }

    /**
     * @return array{tokens: float, updated: float}
     */
    private function bucketSnapshot(string $ip): array
    {
        $ref = new ReflectionClass(RateLimitMiddleware::class);
        $prop = $ref->getProperty('buckets');
        /** @var array<string, array{tokens: float, updated: float}> $buckets */
        $buckets = $prop->getValue();
        self::assertArrayHasKey($ip, $buckets, "No bucket for IP {$ip}");
        /** @var array{tokens: float, updated: float} $bucket */
        $bucket = $buckets[$ip];
        return $bucket;
    }
}
