<?php

declare(strict_types=1);

namespace Framework\Http\Middleware;

use Framework\Container\ContainerException;
use Framework\Container\ContainerInterface;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;

final class Pipeline
{
    /** @var list<MiddlewareInterface|string> */
    private array $middleware = [];

    /** @var list<MiddlewareLink>|null */
    private ?array $compiled = null;

    public function __construct(
        private readonly ?ContainerInterface $container = null,
    ) {
    }

    /**
     * Append a middleware (instance or class-string resolved through the
     * container) to the pipeline. PSR-15 / Slim / Laravel all call this
     * `pipe()`; the framework adopted that name in R5.
     *
     * @param MiddlewareInterface|class-string<MiddlewareInterface> $middleware
     */
    public function pipe(MiddlewareInterface|string $middleware): void
    {
        $this->middleware[] = $middleware;
        $this->compiled = null;
    }

    /**
     * @param callable(Request): Response $core
     */
    public function process(Request $request, callable $core): Response
    {
        if ($this->middleware === []) {
            return $core($request);
        }
        $head = $this->compile($core);
        return $head($request);
    }

    /**
     * @param callable(Request): Response $core
     */
    private function compile(callable $core): MiddlewareLink
    {
        if ($this->compiled !== null) {
            return $this->compiled[0];
        }

        $next = $core;
        $links = [];
        foreach (array_reverse($this->middleware) as $entry) {
            $link = new MiddlewareLink($this->resolve($entry), $next);
            $links[] = $link;
            $next = $link;
        }

        $this->compiled = array_reverse($links);
        return $this->compiled[0];
    }

    private function resolve(MiddlewareInterface|string $entry): MiddlewareInterface
    {
        if ($entry instanceof MiddlewareInterface) {
            return $entry;
        }

        $container = $this->container;
        if ($container === null) {
            throw new ContainerException(
                "Cannot resolve middleware class '{$entry}': no Container provided",
            );
        }

        $instance = $container->get($entry);
        if (!$instance instanceof MiddlewareInterface) {
            throw new ContainerException(
                "Resolved '{$entry}' is not a MiddlewareInterface",
            );
        }

        return $instance;
    }
}
