<?php

declare(strict_types=1);

namespace Framework\Http\Exception;

use Throwable;

class BadGatewayHttpException extends HttpException
{
    public function __construct(string $message = 'Bad Gateway', ?Throwable $previous = null)
    {
        parent::__construct(502, $message, 'about:blank', $previous);
    }
}
