<?php

declare(strict_types=1);

namespace Framework\Validation\Rule;

final class FloatRule implements RuleInterface
{
    public function validate(mixed $value, array $params): ?string
    {
        if ($value === null) {
            return null;
        }
        return is_float($value) ? null : 'Field must be a float';
    }

    public function name(): string
    {
        return 'float';
    }

    public function params(): array
    {
        return [];
    }
}
