<?php

declare(strict_types=1);

namespace Framework\Http\Exception;

use Throwable;

class TooManyRequestsHttpException extends HttpException
{
    private ?int $retryAfter;

    public function __construct(
        string $message = 'Too Many Requests',
        ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        $this->retryAfter = $retryAfter;
        parent::__construct(429, $message, 'about:blank', $previous);
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [];
        if ($this->retryAfter !== null) {
            $headers['Retry-After'] = (string) $this->retryAfter;
        }
        return $headers;
    }
}
