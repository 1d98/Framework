<?php

declare(strict_types=1);

namespace Framework\Tests\Support;

final class HttpResponseParser
{
    /**
     * @return array{0: string, 1: string} [rawHeaders, body]
     */
    public static function split(string $raw, int $headerSize): array
    {
        return [substr($raw, 0, $headerSize), substr($raw, $headerSize)];
    }

    /**
     * @return array<string, string>
     */
    public static function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[trim($name)] = trim($value);
            }
        }
        return $headers;
    }

    public static function headerValue(string $rawHeaders, string $name): string
    {
        $needle = $name . ':';
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (stripos($line, $needle) === 0) {
                return trim(substr($line, strlen($needle)));
            }
        }
        return '';
    }
}
