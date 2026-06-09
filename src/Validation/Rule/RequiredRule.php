<?php

declare(strict_types=1);

namespace Framework\Validation\Rule;

final class RequiredRule implements RuleInterface
{
    public function validate(mixed $value, array $params): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return 'Field is required';
        }
        return null;
    }

    public function name(): string
    {
        return 'required';
    }

    public function params(): array
    {
        return [];
    }
}
