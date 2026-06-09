<?php

declare(strict_types=1);

namespace Framework\Http\Exception;

use Framework\Exception\FrameworkException;
use Throwable;

class HttpException extends FrameworkException
{
    public const STATUS_TEXTS = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        413 => 'Payload Too Large',
        415 => 'Unsupported Media Type',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

    public readonly string $type;
    public readonly string $title;

    public function __construct(
        public readonly int $statusCode,
        string $message = '',
        string $type = 'about:blank',
        ?Throwable $previous = null,
    ) {
        $this->type = $type;
        $this->title = self::STATUS_TEXTS[$statusCode] ?? 'Error';
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * Additional response headers to attach when rendering this exception.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [];
    }
}
