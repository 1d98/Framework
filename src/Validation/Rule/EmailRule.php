<?php

declare(strict_types=1);

namespace Framework\Validation\Rule;

final class EmailRule implements RuleInterface
{
    public function validate(mixed $value, array $params): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            return 'Field must be a valid email';
        }
        return filter_var($value, FILTER_VALIDATE_EMAIL) === false ? 'Field must be a valid email' : null;
    }

    public function name(): string
    {
        return 'email';
    }

    public function params(): array
    {
        return [];
    }
}
