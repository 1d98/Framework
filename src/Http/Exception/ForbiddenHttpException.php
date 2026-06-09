<?php

declare(strict_types=1);

namespace Framework\Http\Exception;

use Throwable;

class ForbiddenHttpException extends HttpException
{
    public function __construct(string $message = 'Forbidden', ?Throwable $previous = null)
    {
        parent::__construct(403, $message, 'about:blank', $previous);
    }
}
