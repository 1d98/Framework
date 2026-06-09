<?php

declare(strict_types=1);

namespace Framework\Http\Exception;

use Throwable;

class InternalServerErrorHttpException extends HttpException
{
    public function __construct(string $message = 'Internal Server Error', ?Throwable $previous = null)
    {
        parent::__construct(500, $message, 'about:blank', $previous);
    }
}
