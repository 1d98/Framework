<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Middleware;

use Framework\Container\Container;
use Framework\Container\ContainerException;
use Framework\Http\Middleware\MiddlewareInterface;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Pipeline::class)]
final class PipelineClassStringTest extends TestCase
{
    public function testClassStringIsResolvedThroughContainer(): void
    {
        $container = new Container();
        $container->set(TagMiddleware::class, static fn(): TagMiddleware => new TagMiddleware('from-container'));

        $pipeline = new Pipeline($container);
        $pipeline->pipe(TagMiddleware::class);

        $response = $pipeline->process(
            new Request('GET', '/'),
            static fn(): Response => Response::text('core'),
        );

        self::assertSame('core', $response->body);
        self::assertSame('from-container', $response->headers['X-Tag'] ?? null);
    }

    public function testMixedInstancesAndClassStrings(): void
    {
        $container = new Container();
        $container->set(TagMiddleware::class, static fn(): TagMiddleware => new TagMiddleware('resolved'));

        $pipeline = new Pipeline($container);
        $pipeline->pipe(new TagMiddleware('first'));
        $pipeline->pipe(TagMiddleware::class);

        $response = $pipeline->process(
            new Request('GET', '/'),
            static fn(): Response => Response::text('core'),
        );

        self::assertSame('first', $response->headers['X-Tag'] ?? null);
    }

    public function testClassStringWithoutContainerThrows(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Cannot resolve middleware class");

        $pipeline = new Pipeline();
        $pipeline->pipe(TagMiddleware::class);

        $pipeline->process(new Request('GET', '/'), static fn(): Response => Response::text('core'));
    }

    public function testResolvedInstanceMustImplementInterface(): void
    {
        $container = new Container();
        $container->set(NotAMiddleware::class, static fn(): NotAMiddleware => new NotAMiddleware());

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("is not a MiddlewareInterface");

        $pipeline = new Pipeline($container);
        // @phpstan-ignore-next-line argument.type — intentional: testing rejection of non-middleware after Container resolution
        $pipeline->pipe(NotAMiddleware::class);

        $pipeline->process(new Request('GET', '/'), static fn(): Response => Response::text('core'));
    }

    public function testInterfaceInstanceStillWorks(): void
    {
        $pipeline = new Pipeline();
        $pipeline->pipe(new TagMiddleware('direct'));

        $response = $pipeline->process(
            new Request('GET', '/'),
            static fn(): Response => Response::text('core'),
        );

        self::assertSame('direct', $response->headers['X-Tag'] ?? null);
    }
}

final class TagMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $tag)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        return $next($request)->withHeader('X-Tag', $this->tag);
    }
}

final class NotAMiddleware
{
}
