<?php

declare(strict_types=1);

namespace Framework\Http\Exception;

use Throwable;

class ConflictHttpException extends HttpException
{
    public function __construct(string $message = 'Conflict', ?Throwable $previous = null)
    {
        parent::__construct(409, $message, 'about:blank', $previous);
    }
}
