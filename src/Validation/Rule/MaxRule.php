<?php

declare(strict_types=1);

namespace Framework\Validation\Rule;

final class MaxRule implements RuleInterface
{
    public function __construct(private int|float $max)
    {
    }

    public function validate(mixed $value, array $params): ?string
    {
        $max = $params['max'] ?? $this->max;
        if (!is_int($max) && !is_float($max)) {
            return 'MaxRule requires int|float max';
        }
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return strlen($value) <= $max ? null : "Field must be at most {$max} characters";
        }
        if (is_int($value) || is_float($value)) {
            return $value <= $max ? null : "Field must be at most {$max}";
        }
        if (is_array($value)) {
            return count($value) <= $max ? null : "Field must have at most {$max} items";
        }
        return 'Field type not supported by MaxRule';
    }

    public function name(): string
    {
        return 'max';
    }

    public function params(): array
    {
        return ['max' => $this->max];
    }
}
