<?php

declare(strict_types=1);

namespace Framework\Http\Exception;

use Throwable;

class PreconditionFailedHttpException extends HttpException
{
    public function __construct(string $message = 'Precondition Failed', ?Throwable $previous = null)
    {
        parent::__construct(412, $message, 'about:blank', $previous);
    }
}
