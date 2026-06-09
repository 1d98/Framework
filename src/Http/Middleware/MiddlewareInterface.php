<?php

declare(strict_types=1);

namespace Framework\Http\Middleware;

use Framework\Http\Request\Request;
use Framework\Http\Response\Response;

interface MiddlewareInterface
{
    /**
     * @param callable(Request): Response $next
     */
    public function process(Request $request, callable $next): Response;
}
