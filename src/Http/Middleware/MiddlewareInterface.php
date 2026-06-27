<?php

declare(strict_types=1);

namespace Framework\Http\Middleware;

use Framework\Http\Request\Request;
use Framework\Http\Response\ResponseInterface;

interface MiddlewareInterface
{
    /**
     * @param callable(Request): ResponseInterface $next
     */
    public function process(Request $request, callable $next): ResponseInterface;
}
