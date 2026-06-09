<?php

declare(strict_types=1);

namespace Framework\Http\Middleware;

use Framework\Exception\ConfigException;
use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use InvalidArgumentException;

final class HttpsRedirectMiddleware implements MiddlewareInterface
{
    /**
     * @var list<string>
     */
    private readonly array $trustedHosts;

    /**
     * @param list<string> $trustedHosts Hostname patterns this deployment
     *     considers safe to reflect into a redirect `Location:` header.
     *     Empty by default — passing nothing forces operators to make
     *     the trust list explicit, otherwise the middleware refuses to
     *     boot. See {@see \Framework\Http\Request\Request::host()} for
     *     the matching semantics (case-insensitive, port-stripped,
     *     `*.example.com` wildcards).
     * @param list<string>|null $trustedProxies CIDR / IP list allowed to
     *     assert HTTPS via `X-Forwarded-Proto`. When `null` or `[]`,
     *     the header is NEVER trusted and a request is treated as HTTPS
     *     only when the immediate connection really is. See
     *     {@see \Framework\Http\Request\Request::isSecure()}.
     */
    public function __construct(
        private readonly int $statusCode = 301,
        array $trustedHosts = [],
        private readonly ?array $trustedProxies = null,
    ) {
        if ($this->statusCode !== 301 && $this->statusCode !== 308) {
            throw new InvalidArgumentException('HttpsRedirect: statusCode must be 301 or 308');
        }

        $normalized = [];
        foreach ($trustedHosts as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                throw new ConfigException('HttpsRedirect: trustedHosts must be a list of non-empty strings');
            }
            $normalized[] = $pattern;
        }

        if ($normalized === []) {
            throw new ConfigException(
                'HttpsRedirect: trustedHosts must be configured (e.g. ["example.com", "*.example.com"]). '
                . 'Set APP_TRUSTED_HOSTS in the environment to silence this error.'
            );
        }

        $this->trustedHosts = $normalized;
    }

    public function process(Request $request, callable $next): Response
    {
        if ($request->isSecure($this->trustedProxies)) {
            /** @var Response $response */
            $response = $next($request);
            return $response;
        }

        try {
            $host = $request->host($this->trustedHosts);
        } catch (BadRequestHttpException $e) {
            throw $e;
        }

        $scheme = 'https';
        $path = $request->path;
        $query = $request->queryString !== '' ? '?' . $request->queryString : '';
        $location = "{$scheme}://{$host}{$path}{$query}";

        return Response::empty($this->statusCode)
            ->withHeader('Location', $location);
    }
}
