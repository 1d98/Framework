<?php

declare(strict_types=1);

namespace Framework\Validation\Rule;

final class IntegerRule implements RuleInterface
{
    public function validate(mixed $value, array $params): ?string
    {
        if ($value === null) {
            return null;
        }
        return is_int($value) ? null : 'Field must be an integer';
    }

    public function name(): string
    {
        return 'integer';
    }

    public function params(): array
    {
        return [];
    }
}
