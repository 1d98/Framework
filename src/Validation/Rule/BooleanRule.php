<?php

declare(strict_types=1);

namespace Framework\Validation\Rule;

final class BooleanRule implements RuleInterface
{
    public function validate(mixed $value, array $params): ?string
    {
        if ($value === null) {
            return null;
        }
        return is_bool($value) ? null : 'Field must be a boolean';
    }

    public function name(): string
    {
        return 'boolean';
    }

    public function params(): array
    {
        return [];
    }
}
