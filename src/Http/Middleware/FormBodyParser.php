<?php

declare(strict_types=1);

namespace Framework\Http\Middleware;

use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\SafeParseStr;

final class FormBodyParser implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        if (!in_array($request->method, ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        if ($request->form !== null) {
            return $next($request);
        }

        $contentType = $request->header('content-type') ?? '';
        $mime = strtolower(trim(explode(';', $contentType, 2)[0]));

        if ($mime !== 'application/x-www-form-urlencoded') {
            return $next($request);
        }

        if ($request->body === '') {
            return $next($request->withForm([]));
        }

        $data = SafeParseStr::parse($request->body);
        /** @var array<string, string|list<string>> $data */
        return $next($request->withForm($data));
    }
}
