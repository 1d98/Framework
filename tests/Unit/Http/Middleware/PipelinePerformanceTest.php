<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Middleware;

use Framework\Http\Middleware\MiddlewareInterface;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(Pipeline::class)]
final class PipelinePerformanceTest extends TestCase
{
    public function testProcessAllocatesMinimalMemory(): void
    {
        $pipeline = new Pipeline();
        for ($i = 0; $i < 6; $i++) {
            $pipeline->pipe(new TagAppendMiddleware("M{$i}"));
        }

        $request = new Request('GET', '/');
        $core = static fn(Request $r): Response => Response::text('core');

        for ($i = 0; $i < 100; $i++) {
            $pipeline->process($request, $core);
        }

        gc_collect_cycles();
        $start = memory_get_usage();
        $iterations = 10_000;
        for ($i = 0; $i < $iterations; $i++) {
            $pipeline->process($request, $core);
        }
        $delta = memory_get_usage() - $start;

        self::assertLessThan(
            200 * 1024,
            $delta,
            "Pipeline allocated {$delta} bytes over {$iterations} process() calls (expected < 204800)",
        );
    }

    public function testConstructorDoesNotCallAnyMiddleware(): void
    {
        $spy = new SpyMiddleware();

        $pipeline = new Pipeline();
        $pipeline->pipe($spy);
        $pipeline->pipe(new SpyMiddleware());

        self::assertSame(0, $spy->calls, 'Constructor must not invoke any middleware');
    }

    public function testChainIsBuiltOnlyOnce(): void
    {
        $spy = new SpyMiddleware();

        $pipeline = new Pipeline();
        $pipeline->pipe($spy);

        $request = new Request('GET', '/');
        $core = static fn(Request $r): Response => Response::text('core');

        $pipeline->process($request, $core);
        $firstCount = $spy->calls;
        self::assertSame(1, $firstCount, 'First process() should invoke the middleware once');

        for ($i = 0; $i < 50; $i++) {
            $pipeline->process($request, $core);
        }
        self::assertSame(51, $spy->calls, 'Subsequent process() calls must not rebuild the chain');
    }

    public function testAddingMiddlewareInvalidatesCompiledChain(): void
    {
        $spyA = new SpyMiddleware();
        $spyB = new SpyMiddleware();

        $pipeline = new Pipeline();
        $pipeline->pipe($spyA);

        $request = new Request('GET', '/');
        $core = static fn(Request $r): Response => Response::text('core');

        $pipeline->process($request, $core);
        self::assertSame(1, $spyA->calls);

        $pipeline->pipe($spyB);
        $pipeline->process($request, $core);

        self::assertSame(2, $spyA->calls, 'Existing middleware should still run after append');
        self::assertSame(1, $spyB->calls, 'Newly added middleware should run on next process()');
    }

    public function testCompiledChainIsStoredOnPipeline(): void
    {
        $pipeline = new Pipeline();
        $pipeline->pipe(new TagAppendMiddleware('only'));

        $request = new Request('GET', '/');
        $core = static fn(Request $r): Response => Response::text('core');

        $pipeline->process($request, $core);

        $property = new ReflectionProperty(Pipeline::class, 'compiled');
        $compiled = $property->getValue($pipeline);
        self::assertIsArray($compiled);
        self::assertCount(1, $compiled);
    }
}

final class SpyMiddleware implements MiddlewareInterface
{
    public int $calls = 0;

    public function process(Request $request, callable $next): Response
    {
        $this->calls++;
        return $next($request);
    }
}

final class TagAppendMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $tag)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        return $next($request)->withHeader('X-Tags', $this->tag);
    }
}
