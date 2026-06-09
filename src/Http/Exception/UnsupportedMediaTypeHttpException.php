<?php

declare(strict_types=1);

namespace Framework\Http\Exception;

use Throwable;

class UnsupportedMediaTypeHttpException extends HttpException
{
    public function __construct(string $message = 'Unsupported Media Type', ?Throwable $previous = null)
    {
        parent::__construct(415, $message, 'about:blank', $previous);
    }
}
