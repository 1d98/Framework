<?php

declare(strict_types=1);

namespace Framework\Http\Middleware;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Request\Request;
use Framework\Http\Response\ResponseInterface;

final class JsonBodyParser implements MiddlewareInterface
{
    public function process(Request $request, callable $next): ResponseInterface
    {
        $contentType = $request->header('Content-Type') ?? '';

        if (!str_starts_with(strtolower($contentType), 'application/json')) {
            return $next($request);
        }

        if ($request->body === '') {
            return $next($request);
        }

        try {
            $decoded = json_decode($request->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BadRequestHttpException('Invalid JSON: ' . $e->getMessage(), $e);
        }

        return $next($request->withJson($decoded));
    }
}
