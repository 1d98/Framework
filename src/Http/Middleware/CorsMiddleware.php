<?php

declare(strict_types=1);

namespace Framework\Http\Middleware;

use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Response\ResponseInterface;
use Framework\Http\Response\Vary;
use InvalidArgumentException;

final class CorsMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $origins       Whitelist (no "*" with credentials)
     * @param list<string> $methods       Default: ['GET','POST','PUT','PATCH','DELETE','OPTIONS']
     * @param list<string> $headers       Default: ['Content-Type','Authorization','X-CSRF-Token']
     * @param list<string> $exposeHeaders Default: []
     * @param bool         $credentials   Default: false
     * @param int          $maxAge        Default: 300 (5 minutes, in seconds)
     */
    public function __construct(
        private readonly array $origins,
        private readonly array $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        private readonly array $headers = ['Content-Type', 'Authorization', 'X-CSRF-Token'],
        private readonly array $exposeHeaders = [],
        private readonly bool $credentials = false,
        private readonly int $maxAge = 300,
    ) {
        if (in_array('*', $this->origins, true) && $this->credentials === true) {
            throw new InvalidArgumentException('CORS: cannot use "*" origin with credentials=true (CORS spec violation)');
        }
    }

    public function process(Request $request, callable $next): ResponseInterface
    {
        $rawOrigin = $request->header('Origin');
        if ($rawOrigin === null) {
            return $this->callNext($request, $next);
        }

        $origin = strtolower(trim($rawOrigin));
        if ($origin === '') {
            return $this->callNext($request, $next);
        }

        $whitelisted = in_array($origin, $this->origins, true);

        if (!$whitelisted) {
            return $this->handleNotWhitelisted($request, $next);
        }

        $isPreflight = $request->method === 'OPTIONS'
            && $request->header('Access-Control-Request-Method') !== null;

        if ($isPreflight) {
            return $this->buildPreflightResponse($request, $origin);
        }

        return $this->decorateWithCorsHeaders($this->callNext($request, $next), $origin);
    }

    private function callNext(Request $request, callable $next): ResponseInterface
    {
        /** @var ResponseInterface $response */
        $response = $next($request);
        return $response;
    }

    private function handleNotWhitelisted(Request $request, callable $next): ResponseInterface
    {
        $isPreflight = $request->method === 'OPTIONS'
            && $request->header('Access-Control-Request-Method') !== null;

        if ($isPreflight) {
            return Response::json(
                [
                    'type' => 'about:blank',
                    'title' => 'Forbidden',
                    'status' => 403,
                    'detail' => 'CORS origin not allowed',
                ],
                403,
            )
                ->withHeader('Content-Type', 'application/problem+json')
                ->withHeader('Vary', $this->buildVaryHeader($request));
        }

        return $this->callNext($request, $next)->withHeader('Vary', 'Origin');
    }

    private function buildPreflightResponse(Request $request, string $origin): Response
    {
        $response = Response::empty(204)
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->methods))
            ->withHeader('Access-Control-Allow-Headers', $this->resolveAllowHeaders($request))
            ->withHeader('Access-Control-Max-Age', (string) $this->maxAge)
            ->withHeader('Vary', $this->buildVaryHeader($request));

        if ($this->credentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    /**
     * Build the preflight `Vary` value by accumulating CORS request-shaping
     * axes onto any existing `Vary` value. CDNs/proxies key their cache on
     * the full Vary set, so collapsing `Origin` + request-method + request-
     * headers would serve the wrong `Allow-Headers` to a different client.
     */
    private function buildVaryHeader(Request $request): string
    {
        $axes = ['Origin', 'Access-Control-Request-Method', 'Access-Control-Request-Headers'];

        foreach (Vary::tokens($request->header('Vary') ?? '') as $token) {
            if (!in_array($token, $axes, true)) {
                $axes[] = $token;
            }
        }

        return implode(', ', $axes);
    }

    /**
     * Decide which headers to echo in `Access-Control-Allow-Headers`.
     *
     * Rules:
     *  - If the client sent `Access-Control-Request-Headers`:
     *      - intersect with the configured allowlist;
     *      - if the intersection is non-empty, echo the intersection (most
     *        precise — never over-allow a header the operator did not list);
     *      - if the allowlist is empty, treat it as "permissive echo" and
     *        return the client's requested headers verbatim;
     *      - otherwise fall back to the configured allowlist.
     *  - If the client did NOT send the request header, echo the configured
     *    allowlist as-is (regression-safe default).
     */
    private function resolveAllowHeaders(Request $request): string
    {
        $configured = $this->headers;
        $requestedRaw = $request->header('Access-Control-Request-Headers');

        if ($requestedRaw === null) {
            return implode(', ', $configured);
        }

        $requested = $this->parseHeaderList($requestedRaw);

        if ($configured === []) {
            return implode(', ', $requested);
        }

        $configuredLower = array_map('strtolower', $configured);
        $allowed = [];
        foreach ($requested as $name) {
            if (in_array(strtolower($name), $configuredLower, true)) {
                $allowed[] = $name;
            }
        }

        return implode(', ', $allowed !== [] ? $allowed : $configured);
    }

    /**
     * @return list<string>
     */
    private function parseHeaderList(string $raw): array
    {
        $parts = explode(',', $raw);
        $out = [];
        foreach ($parts as $part) {
            $name = trim($part);
            if ($name !== '' && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    private function decorateWithCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Vary', 'Origin');

        if ($this->credentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        if ($this->exposeHeaders !== []) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->exposeHeaders));
        }

        return $response;
    }
}
