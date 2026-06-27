<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Middleware;

use Framework\Http\Middleware\MiddlewareInterface;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Response\ResponseInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Pipeline::class)]
final class PipelinePipeTest extends TestCase
{
    public function testPipeAcceptsInstanceAndProcessesIt(): void
    {
        $pipeline = new Pipeline();
        $pipeline->pipe(new TagPipeMiddleware('first'));

        $response = $pipeline->process(
            new Request('GET', '/'),
            static fn(): Response => Response::text('core'),
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('core', $response->body);
        self::assertSame('first', $response->headers['X-Pipe'] ?? null);
    }

    public function testPipeAcceptsClassStringResolvedThroughContainer(): void
    {
        $container = new \Framework\Container\Container();
        $container->set(
            TagPipeMiddleware::class,
            static fn(): TagPipeMiddleware => new TagPipeMiddleware('from-container'),
        );

        $pipeline = new Pipeline($container);
        $pipeline->pipe(TagPipeMiddleware::class);

        $response = $pipeline->process(
            new Request('GET', '/'),
            static fn(): Response => Response::text('core'),
        );

        self::assertSame('from-container', $response->headers['X-Pipe'] ?? null);
    }
}

final class TagPipeMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $tag)
    {
    }

    public function process(Request $request, callable $next): ResponseInterface
    {
        return $next($request)->withHeader('X-Pipe', $this->tag);
    }
}
