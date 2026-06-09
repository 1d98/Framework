<?php

declare(strict_types=1);

namespace Framework\Validation\Rule;

final class UuidRule implements RuleInterface
{
    public function validate(mixed $value, array $params): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            return 'Field must be a valid UUID';
        }
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value,
        ) === 1 ? null : 'Field must be a valid UUID';
    }

    public function name(): string
    {
        return 'uuid';
    }

    public function params(): array
    {
        return [];
    }
}
