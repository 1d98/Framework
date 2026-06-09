<?php

declare(strict_types=1);

namespace Framework\Validation\Rule;

final class UrlRule implements RuleInterface
{
    public function validate(mixed $value, array $params): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            return 'Field must be a valid URL';
        }
        return filter_var($value, FILTER_VALIDATE_URL) === false ? 'Field must be a valid URL' : null;
    }

    public function name(): string
    {
        return 'url';
    }

    public function params(): array
    {
        return [];
    }
}
