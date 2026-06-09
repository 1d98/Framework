<?php

declare(strict_types=1);

namespace Framework\Http\Exception;

use Throwable;

class UnprocessableEntityHttpException extends HttpException
{
    /**
     * @param list<array<string, mixed>> $errors RFC 7807 error entries
     *                                       (e.g. ValidationError::toArray() output).
     */
    public function __construct(
        string $message = 'Unprocessable Entity',
        ?Throwable $previous = null,
        public readonly array $errors = [],
    ) {
        parent::__construct(422, $message, 'about:blank', $previous);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
