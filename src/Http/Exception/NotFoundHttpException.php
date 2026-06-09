<?php

declare(strict_types=1);

namespace Framework\Http\Exception;

use Throwable;

class NotFoundHttpException extends HttpException
{
    public function __construct(string $message = 'Not Found', ?Throwable $previous = null)
    {
        parent::__construct(404, $message, 'about:blank', $previous);
    }
}
