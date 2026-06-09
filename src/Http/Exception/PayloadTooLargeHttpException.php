<?php

declare(strict_types=1);

namespace Framework\Http\Exception;

use Throwable;

class PayloadTooLargeHttpException extends HttpException
{
    public function __construct(string $message = 'Payload Too Large', ?Throwable $previous = null)
    {
        parent::__construct(413, $message, 'about:blank', $previous);
    }
}
