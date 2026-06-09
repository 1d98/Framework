<?php

declare(strict_types=1);

namespace Framework\Validation\Rule;

final class MinRule implements RuleInterface
{
    public function __construct(private int|float $min)
    {
    }

    public function validate(mixed $value, array $params): ?string
    {
        $min = $params['min'] ?? $this->min;
        if (!is_int($min) && !is_float($min)) {
            return 'MinRule requires int|float min';
        }
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return strlen($value) >= $min ? null : "Field must be at least {$min} characters";
        }
        if (is_int($value) || is_float($value)) {
            return $value >= $min ? null : "Field must be at least {$min}";
        }
        if (is_array($value)) {
            return count($value) >= $min ? null : "Field must have at least {$min} items";
        }
        return 'Field type not supported by MinRule';
    }

    public function name(): string
    {
        return 'min';
    }

    public function params(): array
    {
        return ['min' => $this->min];
    }
}
