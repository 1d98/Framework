<?php

declare(strict_types=1);

namespace Framework\Http\Exception;

use Throwable;

class MethodNotAllowedHttpException extends HttpException
{
    /**
     * @param list<string> $allowedMethods HTTP methods registered for the requested path.
     * @param array<string, string> $headers Additional response headers (e.g. 'Allow').
     */
    public function __construct(
        string $message = 'Method Not Allowed',
        ?Throwable $previous = null,
        private readonly array $allowedMethods = [],
        private readonly array $headers = [],
    ) {
        parent::__construct(405, $message, 'about:blank', $previous);
    }

    /**
     * @return list<string>
     */
    public function allowedMethods(): array
    {
        return $this->allowedMethods;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
