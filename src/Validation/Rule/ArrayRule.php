<?php

declare(strict_types=1);

namespace Framework\Validation\Rule;

final class ArrayRule implements RuleInterface
{
    public function validate(mixed $value, array $params): ?string
    {
        if ($value === null) {
            return null;
        }
        return is_array($value) ? null : 'Field must be an array';
    }

    public function name(): string
    {
        return 'array';
    }

    public function params(): array
    {
        return [];
    }
}
