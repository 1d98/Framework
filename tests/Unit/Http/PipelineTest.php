<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Http\Middleware\MiddlewareInterface;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Response\ResponseInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Pipeline::class)]
final class PipelineTest extends TestCase
{
    public function testEmptyPipelineCallsCoreDirectly(): void
    {
        $pipeline = new Pipeline();
        $core = static fn(Request $r): Response => Response::text('core');

        $response = $pipeline->process(new Request('GET', '/'), $core);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('core', $response->body);
    }

    public function testSingleMiddlewareWrapsCore(): void
    {
        $pipeline = new Pipeline();
        $pipeline->pipe(new HeaderMiddleware('X-Wrapped', 'yes'));

        $core = static fn(Request $r): Response => Response::text('core');

        $response = $pipeline->process(new Request('GET', '/'), $core);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('core', $response->body);
        self::assertSame('yes', $response->headers['X-Wrapped']);
    }

    public function testFirstAddedRunsFirstInPreWorkLastInPostWork(): void
    {
        $log = [];
        $pipeline = new Pipeline();
        $pipeline->pipe(new LoggingMiddleware('A', $log));
        $pipeline->pipe(new LoggingMiddleware('B', $log));

        $core = static function (Request $r) use (&$log): Response {
            $log[] = 'core';
            return Response::text('done');
        };

        $pipeline->process(new Request('GET', '/'), $core);

        self::assertSame(['pre-A', 'pre-B', 'core', 'post-B', 'post-A'], $log);
    }

    public function testMiddlewareCanShortCircuitByNotCallingNext(): void
    {
        $log = [];
        $pipeline = new Pipeline();
        $pipeline->pipe(new ShortCircuitMiddleware('A', Response::text('shortcut'), $log));
        $pipeline->pipe(new LoggingMiddleware('B', $log));

        $core = static function () use (&$log): Response {
            $log[] = 'core';
            return Response::text('never');
        };

        $response = $pipeline->process(new Request('GET', '/'), $core);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('shortcut', $response->body);
        self::assertSame(['pre-A'], $log, 'B and core should not be called');
    }
}

final class HeaderMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $value,
    ) {
    }

    public function process(Request $request, callable $next): ResponseInterface
    {
        return $next($request)->withHeader($this->name, $this->value);
    }
}

final class LoggingMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $log
     */
    public function __construct(
        private readonly string $name,
        /** @phpstan-ignore-next-line property.onlyWritten */
        private array &$log,
    ) {
    }

    public function process(Request $request, callable $next): ResponseInterface
    {
        $this->log[] = "pre-{$this->name}";
        $response = $next($request);
        $this->log[] = "post-{$this->name}";
        return $response;
    }
}

final class ShortCircuitMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $log
     */
    public function __construct(
        private readonly string $name,
        private readonly Response $response,
        /** @phpstan-ignore-next-line property.onlyWritten */
        private array &$log,
    ) {
    }

    public function process(Request $request, callable $next): ResponseInterface
    {
        $this->log[] = "pre-{$this->name}";
        return $this->response;
    }
}
