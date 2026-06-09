<?php

declare(strict_types=1);

namespace Framework\Http\Exception;

use Throwable;

class TooManyRequestsHttpException extends HttpException
{
    public function __construct(string $message = 'Too Many Requests', ?Throwable $previous = null)
    {
        parent::__construct(429, $message, 'about:blank', $previous);
    }
}
