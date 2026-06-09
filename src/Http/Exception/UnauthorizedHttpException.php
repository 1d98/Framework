<?php

declare(strict_types=1);

namespace Framework\Http\Exception;

use Throwable;

class UnauthorizedHttpException extends HttpException
{
    public function __construct(string $message = 'Unauthorized', ?Throwable $previous = null)
    {
        parent::__construct(401, $message, 'about:blank', $previous);
    }
}
