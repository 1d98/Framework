<?php

declare(strict_types=1);

namespace Framework\Http\Middleware;

use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Response\Vary;
use InvalidArgumentException;

final class CompressionMiddleware implements MiddlewareInterface
{
    /**
     * @param int $threshold Min body size in bytes to compress. Default 1024 (1KB).
     * @param int $level gzip level 1-9; 0 short-circuits to no compression. Default 6 (zlib default, balanced).
     * @param list<string> $compressibleTypes Substring match against Content-Type header.
     */
    public function __construct(
        private readonly int $threshold = 1024,
        private readonly int $level = 6,
        private readonly array $compressibleTypes = [
            'text/',
            'application/json',
            'application/problem+json',
            'application/xml',
            'application/javascript',
            'application/xhtml+xml',
            'application/rss+xml',
            'application/atom+xml',
        ],
    ) {
        if ($threshold < 0) {
            throw new InvalidArgumentException('Compression threshold cannot be negative');
        }
        if ($level < 0 || $level > 9) {
            throw new InvalidArgumentException('Compression level must be between 0 and 9');
        }
    }

    public function process(Request $request, callable $next): Response
    {
        $acceptEncoding = $request->header('Accept-Encoding');
        if ($acceptEncoding === null || !$this->clientAcceptsGzip($acceptEncoding)) {
            /** @var Response $response */
            $response = $next($request);
            return $response;
        }

        /** @var Response $response */
        $response = $next($request);

        if (!$this->shouldCompress($response)) {
            return $response;
        }

        if ($this->level < 1) {
            return $response;
        }

        $compressed = gzencode($response->body, $this->level);
        if ($compressed === false) {
            return $response;
        }

        return $response
            ->withBody($compressed)
            ->withHeader('Content-Encoding', 'gzip')
            ->withHeader('Content-Length', (string) strlen($compressed))
            ->withHeader('Vary', $this->mergeVary($response, 'Accept-Encoding'));
    }

    private function clientAcceptsGzip(string $header): bool
    {
        foreach (explode(',', $header) as $token) {
            $parts = explode(';', trim($token), 2);
            $coding = strtolower(trim($parts[0]));
            if ($coding === 'gzip') {
                return true;
            }
        }
        return false;
    }

    private function shouldCompress(Response $response): bool
    {
        if ($response->status >= 300) {
            return false;
        }

        if (strlen($response->body) < $this->threshold) {
            return false;
        }

        if (array_key_exists('Content-Encoding', $response->headers)) {
            return false;
        }

        if (array_key_exists('Transfer-Encoding', $response->headers)) {
            return false;
        }

        $contentType = $response->headers['Content-Type'] ?? '';
        if ($contentType === '') {
            return false;
        }

        $normalized = strtolower($contentType);
        foreach ($this->compressibleTypes as $type) {
            if (str_contains($normalized, strtolower($type))) {
                return true;
            }
        }
        return false;
    }

    private function mergeVary(Response $response, string $token): string
    {
        return Vary::merge($response->headers['Vary'] ?? '', $token);
    }
}
