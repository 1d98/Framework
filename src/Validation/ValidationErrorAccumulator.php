<?php

declare(strict_types=1);

namespace Framework\Validation;

/**
 * Internal mutator used by {@see Validator} while collecting errors during
 * a validation pass. It is intentionally mutable and not exposed as a value
 * object — at the end of the pass it produces an immutable
 * {@see ValidationErrorCollection}. Keep this class out of the public surface
 * area: it is an implementation detail of the validation pipeline, not part
 * of the framework's API contract.
 */
final class ValidationErrorAccumulator
{
    /** @var list<ValidationError> */
    private array $errors = [];

    public function add(ValidationError $error): void
    {
        $this->errors[] = $error;
    }

    public function isEmpty(): bool
    {
        return $this->errors === [];
    }

    public function toCollection(): ValidationErrorCollection
    {
        return new ValidationErrorCollection($this->errors);
    }
}
