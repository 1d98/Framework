<?php

declare(strict_types=1);

namespace Framework\Validation\Rule;

final class StringRule implements RuleInterface
{
    public function validate(mixed $value, array $params): ?string
    {
        if ($value === null) {
            return null;
        }
        return is_string($value) ? null : 'Field must be a string';
    }

    public function name(): string
    {
        return 'string';
    }

    public function params(): array
    {
        return [];
    }
}
