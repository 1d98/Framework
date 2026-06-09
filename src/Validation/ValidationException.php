<?php

declare(strict_types=1);

namespace Framework\Validation;

use Framework\Exception\FrameworkException;
use Throwable;

final class ValidationException extends FrameworkException
{
    public function __construct(
        public readonly ValidationErrorCollection $errors,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Validation failed', 0, $previous);
    }

    public function errors(): ValidationErrorCollection
    {
        return $this->errors;
    }
}
