<?php

declare(strict_types=1);

namespace Framework\Http\Exception;

use Throwable;

class ServiceUnavailableHttpException extends HttpException
{
    public function __construct(string $message = 'Service Unavailable', ?Throwable $previous = null)
    {
        parent::__construct(503, $message, 'about:blank', $previous);
    }
}
