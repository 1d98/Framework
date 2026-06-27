<?php

declare(strict_types=1);

namespace Framework\Http\Middleware;

use Framework\Http\Request\Request;
use Framework\Http\Response\ResponseInterface;

/**
 * Pre-bound dispatch node in a middleware chain. Each link holds its middleware
 * and a callable that dispatches to the next link (or the core handler). A single
 * link instance is reused for every request, so the chain pays no per-request
 * closure allocation.
 */
final class MiddlewareLink
{
    /**
     * @param MiddlewareInterface  $middleware
     * @param callable(Request): ResponseInterface $next
     */
    public function __construct(
        private readonly MiddlewareInterface $middleware,
        private $next,
    ) {
    }

    public function __invoke(Request $request): ResponseInterface
    {
        /** @var callable(Request): ResponseInterface $next */
        $next = $this->next;
        return $this->middleware->process($request, $next);
    }
}
