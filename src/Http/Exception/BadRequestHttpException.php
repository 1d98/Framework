<?php

declare(strict_types=1);

namespace Framework\Http\Exception;

use Throwable;

class BadRequestHttpException extends HttpException
{
    public function __construct(string $message = 'Bad Request', ?Throwable $previous = null)
    {
        parent::__construct(400, $message, 'about:blank', $previous);
    }
}
