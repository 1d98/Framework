<?php

declare(strict_types=1);

namespace Framework\Validation;

use Countable;

final readonly class ValidationErrorCollection implements Countable
{
    /** @var list<ValidationError> */
    private array $errors;

    /**
     * @param list<ValidationError> $errors
     */
    public function __construct(array $errors = [])
    {
        $this->errors = array_values($errors);
    }

    /**
     * @return list<ValidationError>
     */
    public function all(): array
    {
        return $this->errors;
    }

    public function isEmpty(): bool
    {
        return $this->errors === [];
    }

    public function count(): int
    {
        return count($this->errors);
    }

    /**
     * @return list<ValidationError>
     */
    public function forProperty(string $name): array
    {
        $out = [];
        foreach ($this->errors as $error) {
            if ($error->property === $name) {
                $out[] = $error;
            }
        }
        return $out;
    }

    /**
     * Grouped form for RFC 7807 details:
     *   { "email": [{"rule":"email","message":"..."}, ...], "age": [...] }
     *
     * @return array<string, list<array{rule: string, message: string}>>
     */
    public function toArray(): array
    {
        $grouped = [];
        foreach ($this->errors as $error) {
            $grouped[$error->property][] = [
                'rule' => $error->rule,
                'message' => $error->message,
            ];
        }
        return $grouped;
    }
}
